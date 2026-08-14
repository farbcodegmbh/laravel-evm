<?php

namespace Farbcode\LaravelEvm\Clients;

use Farbcode\LaravelEvm\Contracts\RpcClient;
use Farbcode\LaravelEvm\Exceptions\RpcErrorException;
use Farbcode\LaravelEvm\Exceptions\RpcException;
use Farbcode\LaravelEvm\Exceptions\RpcTransportException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use kornrunner\Keccak;
use Throwable;

/**
 * JSON-RPC over HTTP with failover across several endpoints.
 *
 * Failover is for transport problems only. A JSON-RPC error is the node's
 * verdict on the request and will be identical everywhere, so it is returned to
 * the caller rather than retried against the next provider.
 */
class RpcHttpClient implements RpcClient
{
    protected array $urls;

    protected int $chainId;

    /**
     * Index of the endpoint currently in use. It only moves when that endpoint
     * fails, so a broadcast and the receipt polling that follows stay on one
     * provider instead of asking a node that has never seen the transaction.
     */
    protected int $cursor = 0;

    private int $timeout;

    private int $connectTimeout;

    private int $tries;

    /**
     * Errors that mean "this transaction is already accepted", not "this failed".
     */
    private const ACCEPTED_SEND_ERRORS = [
        'already known',
        'already imported',
        'transaction already imported',
        'nonce too low',
        'known transaction',
    ];

    public function __construct(array $urls, int $chainId, array $options = [])
    {
        if (empty($urls)) {
            throw new RpcException('No RPC URLs configured');
        }

        $this->urls = array_values($urls);
        $this->chainId = $chainId;
        $this->timeout = (int) ($options['timeout'] ?? 10);
        $this->connectTimeout = (int) ($options['connect_timeout'] ?? 3);
        $this->tries = max(1, (int) ($options['tries'] ?? 2));
    }

    /**
     * Perform a raw JSON RPC call.
     *
     * Returns the decoded JSON-RPC envelope, which may carry either `result` or
     * `error`. Only a transport failure on every endpoint throws.
     */
    public function callRaw(string $method, array $params = []): array
    {
        // Unique id per request helps correlate logs on some providers
        $id = Str::uuid()->toString();

        $payload = [
            'jsonrpc' => '2.0',
            'id' => $id,
            'method' => $method,
            'params' => $params,
        ];

        $lastError = null;

        for ($attempt = 0; $attempt < count($this->urls); $attempt++) {
            $index = $this->cursor % count($this->urls);

            try {
                $response = Http::withHeaders(['Content-Type' => 'application/json'])
                    // Retry the transport, never the verdict: a 4xx is a bad key
                    // or a rate limit and repeating it only makes things worse.
                    ->retry($this->tries, 200, function ($exception) {
                        return $exception instanceof ConnectionException
                            || ($exception->response?->serverError() ?? false);
                    }, throw: false)
                    ->connectTimeout($this->connectTimeout)
                    ->timeout($this->timeout)
                    ->post($this->urls[$index], $payload);

                if ($response->successful()) {
                    $json = $response->json();

                    if (is_array($json)) {
                        return $json;
                    }

                    $lastError = 'Invalid JSON body';
                    Log::warning('RPC invalid json', [
                        'endpoint' => $this->describe($index),
                        'method' => $method,
                        'id' => $id,
                        'body' => $response->body(),
                    ]);
                } else {
                    $lastError = 'HTTP '.$response->status();
                    Log::warning('RPC non success', [
                        'endpoint' => $this->describe($index),
                        'method' => $method,
                        'id' => $id,
                        'status' => $response->status(),
                        'body' => $response->body(),
                    ]);
                }
            } catch (Throwable $e) {
                $lastError = $e->getMessage();
                Log::error('RPC exception', [
                    'endpoint' => $this->describe($index),
                    'method' => $method,
                    'id' => $id,
                    'error' => $lastError,
                ]);
            }

            // This endpoint is unusable; move on and stay there.
            $this->cursor++;
        }

        throw new RpcTransportException('All RPC endpoints failed. Error: '.$lastError);
    }

    /**
     * Perform a call and return the result field.
     *
     * Throws RpcErrorException when the node reports a JSON-RPC error, carrying
     * the original code and data so a caller can tell a revert from an outage.
     */
    public function call(string $method, array $params = []): mixed
    {
        $json = $this->callRaw($method, $params);

        if (isset($json['error'])) {
            $error = $json['error'];
            $message = is_array($error) ? ($error['message'] ?? 'RPC error') : (string) $error;

            if ($method === 'eth_sendRawTransaction' && $this->isAlreadyAccepted($message)) {
                // The node is telling us the transaction is in the pool. Treat
                // that as the success it is, and recover the hash locally so the
                // caller can keep polling for a receipt.
                Log::info('RPC send reported an already accepted transaction', [
                    'method' => $method,
                    'error' => $message,
                ]);

                return self::transactionHash((string) ($params[0] ?? ''));
            }

            Log::warning('RPC error body', [
                'method' => $method,
                'error' => $error,
            ]);

            throw new RpcErrorException(
                $message,
                is_array($error) && isset($error['code']) ? (int) $error['code'] : null,
                is_array($error) ? ($error['data'] ?? null) : null,
            );
        }

        if (! array_key_exists('result', $json)) {
            // Unexpected shape; return whole response for caller inspection
            return $json;
        }

        return $json['result'];
    }

    /**
     * The hash of a signed transaction is the keccak256 of its raw bytes, so it
     * can be recovered without asking the node.
     */
    public static function transactionHash(string $rawHex): string
    {
        $clean = preg_replace('/^0[xX]/', '', $rawHex);
        $bin = $clean === '' ? false : hex2bin($clean);

        if ($bin === false) {
            throw new RpcException('Cannot derive a transaction hash from the raw payload');
        }

        return '0x'.Keccak::hash($bin, 256);
    }

    private function isAlreadyAccepted(string $message): bool
    {
        $normalized = strtolower($message);

        foreach (self::ACCEPTED_SEND_ERRORS as $needle) {
            if (str_contains($normalized, $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Health check returning the chain id and latest block per endpoint, so a
     * url pointing at the wrong chain is visible instead of silent.
     */
    public function health(): array
    {
        $pinned = $this->cursor;
        $endpoints = [];

        foreach (array_keys($this->urls) as $index) {
            $this->cursor = $index;

            try {
                $chainId = (int) hexdec((string) $this->call('eth_chainId'));
                $endpoints[self::redact($this->urls[$index])] = [
                    'chainId' => $chainId,
                    'block' => (int) hexdec((string) $this->call('eth_blockNumber')),
                    'matchesConfiguredChain' => $chainId === $this->chainId,
                ];
            } catch (Throwable $e) {
                $endpoints[self::redact($this->urls[$index])] = ['error' => $e->getMessage()];
            }
        }

        $this->cursor = $pinned;

        $first = reset($endpoints) ?: [];

        return [
            'rpc_urls' => implode(', ', array_map(self::redact(...), $this->urls)),
            'chainId' => $first['chainId'] ?? null,
            'block' => $first['block'] ?? null,
            'endpoints' => $endpoints,
        ];
    }

    /**
     * Identify an endpoint in a log line without disclosing its credentials.
     */
    private function describe(int $index): string
    {
        return '#'.$index.' '.self::redact($this->urls[$index]);
    }

    /**
     * Provider URLs normally carry the API key in the path or the query string,
     * so only the scheme, host and port may be shown. Everything that could
     * hold a secret is replaced, not shortened.
     */
    public static function redact(string $url): string
    {
        $parts = parse_url($url);

        if ($parts === false || ! isset($parts['host'])) {
            return '[unparsable url]';
        }

        $base = ($parts['scheme'] ?? 'https').'://'.$parts['host'];

        if (isset($parts['port'])) {
            $base .= ':'.$parts['port'];
        }

        $carriesSecret = isset($parts['user'])
            || isset($parts['query'])
            || ! in_array($parts['path'] ?? '', ['', '/'], true);

        return $carriesSecret ? $base.'/***' : $base;
    }

    /**
     * Get logs wrapper calling eth_getLogs with validation
     */
    public function getLogs(array $filter): array
    {
        // Basic validation: require either blockHash or fromBlock/toBlock pair.
        if (! isset($filter['blockHash']) && ! isset($filter['fromBlock']) && ! isset($filter['toBlock'])) {
            throw new \InvalidArgumentException('getLogs filter requires blockHash or fromBlock/toBlock');
        }
        $res = $this->call('eth_getLogs', [$filter]);

        return is_array($res) ? $res : [];
    }
}
