<?php

namespace Farbcode\LaravelEvm\Clients;

use Farbcode\LaravelEvm\Contracts\AbiCodec;
use Farbcode\LaravelEvm\Contracts\ContractClient;
use Farbcode\LaravelEvm\Contracts\FeePolicy;
use Farbcode\LaravelEvm\Contracts\NonceManager;
use Farbcode\LaravelEvm\Contracts\RpcClient;
use Farbcode\LaravelEvm\Contracts\Signer;
use Farbcode\LaravelEvm\Jobs\SendTransaction;

class ContractClientGeneric implements ContractClient
{
    public function __construct(
        private RpcClient $rpc,
        private Signer $signer,
        private AbiCodec $abi,
        private int $chainId,
        private array $txCfg
    ) {}

    private string $address = '';

    private array|string $abiJson = [];

    public function at(string $address, array|string $abi = []): self
    {
        $this->address = $address;
        $this->abiJson = $abi;

        return $this;
    }

    public function call(string $function, array $args = []): mixed
    {
        $from = $this->signer->getAddress();
        $data = $this->abi->encodeFunction($this->abiJson, $function, $args);

        return $this->rpc->call('eth_call', [[
            'from' => $from,
            'to' => $this->address,
            'data' => $data,
        ], 'latest']);
    }

    public function estimateGas(string $data, ?string $from = null): int
    {
        $from = $from ?: $this->signer->getAddress();
        $est = $this->rpc->call('eth_estimateGas', [[
            'from' => $from,
            'to' => $this->address,
            'data' => $data,
        ]]);
        $n = is_string($est) ? hexdec($est) : (int) $est;
        $pad = (float) ($this->txCfg['estimate_padding'] ?? 1.2);

        return (int) max(150000, ceil($n * $pad));
    }

    public function sendAsync(string $function, array $args = [], array $opts = []): string
    {
        $data = $this->abi->encodeFunction($this->abiJson, $function, $args);
        $queue = (string) ($this->txCfg['queue'] ?? 'evm-send');

        // Generate a pseudo job identifier (not the queue internal ID) for tracking
        $requestId = \Illuminate\Support\Str::uuid()->toString();
        dispatch(new SendTransaction(
            address: $this->address,
            data: $data,
            opts: array_merge($opts, ['request_id' => $requestId]),
            chainId: $this->chainId,
            txCfg: $this->txCfg
        ))->onQueue($queue);
        return $requestId;
    }

    public function wait(string $txHash, int $timeoutSec = 120, int $pollMs = 800): ?array
    {
        $deadline = time() + $timeoutSec;
        while (time() < $deadline) {
            $rec = $this->rpc->call('eth_getTransactionReceipt', [$txHash]);
            if (! empty($rec)) {
                return $rec;
            }
            usleep($pollMs * 1000);
        }

        return null;
    }
}
