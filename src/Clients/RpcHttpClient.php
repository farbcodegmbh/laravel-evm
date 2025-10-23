<?php

namespace Farbcode\LaravelEvm\Clients;

use Farbcode\LaravelEvm\Contracts\RpcClient;
use Farbcode\LaravelEvm\Exceptions\RpcException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class RpcHttpClient implements RpcClient
{
    /**
     * JSON RPC over HTTP with round robin fallback across multiple endpoints
     * Uses Laravel HTTP client with retry and timeout
     */
    protected array $urls;

    protected int $chainId;

    protected int $cursor = 0;

    public function __construct(array $urls, int $chainId)
    {
        if (empty($urls)) {
            throw new RpcException('No RPC URLs configured');
        }

        $this->urls = array_values($urls);
        $this->chainId = $chainId;
    }

    /**
     * Perform a raw JSON RPC call
     * Returns decoded array which may include result or error
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

        $attempts = count($this->urls);
        $lastError = null;

        for ($i = 0; $i < $attempts; $i++) {
            $url = $this->urls[$this->cursor % count($this->urls)];
            $this->cursor++;

            try {
                $response = Http::withHeaders([
                    'Content-Type' => 'application/json',
                ])
                    // retry on connection issues and 5xx only
                    ->retry(2, 200, function ($exception, $request) {
                        return true;
                    }, throw: false)
                    ->timeout(10)
                    ->post($url, $payload);

                // Successful HTTP
                if ($response->successful()) {
                    $json = $response->json();

                    // RPC level error
                    if (is_array($json) && isset($json['error'])) {
                        $lastError = $json['error']['message'] ?? 'RPC error';
                        Log::warning('RPC error body', [
                            'url' => $url,
                            'method' => $method,
                            'id' => $id,
                            'error' => $json['error'],
                        ]);

                        // try next url
                        continue;
                    }

                    if (is_array($json)) {
                        return $json;
                    }

                    // Unexpected body shape
                    $lastError = 'Invalid JSON body';
                    Log::warning('RPC invalid json', [
                        'url' => $url,
                        'method' => $method,
                        'id' => $id,
                        'body' => $response->body(),
                    ]);

                    continue;
                }

                // Non success HTTP
                $lastError = 'HTTP '.$response->status();
                Log::warning('RPC non success', [
                    'url' => $url,
                    'method' => $method,
                    'id' => $id,
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);
                // try next url
            } catch (\Throwable $e) {
                // Network or timeout
                $lastError = $e->getMessage();
                Log::error('RPC exception', [
                    'url' => $url,
                    'method' => $method,
                    'id' => $id,
                    'error' => $lastError,
                ]);
                // try next url
            }
        }

        throw new RpcException('All RPC endpoints failed '.$lastError);
    }

    /**
     * Convenience wrapper returning the result field
     * Throws when the RPC response carries an error
     */
    public function call(string $method, array $params = []): false|string
    {
        $json = $this->callRaw($method, $params);

        if (isset($json['error'])) {
            throw new RpcException(is_array($json['error']) ? json_encode($json['error']) : (string) $json['error']);
        }


        // Some providers return already unwrapped arrays for simple calls
        return isset($json['result']) && is_array($json['result']) ? json_encode($json['result']) : (string) ($json['result'] ?? $json);
    }

    /**
     * Health check returning numeric chain id and latest block number
     */
    public function health(): array
    {
        $idHex = $this->call('eth_chainId');
        $bnHex = $this->call('eth_blockNumber');

        return [
            'chainId' => is_string($idHex) ? hexdec($idHex) : (int) $idHex,
            'block' => is_string($bnHex) ? hexdec($bnHex) : (int) $bnHex,
        ];
    }
}
