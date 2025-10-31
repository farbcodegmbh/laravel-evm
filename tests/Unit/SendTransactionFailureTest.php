<?php

use Farbcode\LaravelEvm\Contracts\FeePolicy;
use Farbcode\LaravelEvm\Contracts\NonceManager;
use Farbcode\LaravelEvm\Contracts\RpcClient;
use Farbcode\LaravelEvm\Contracts\Signer;
use Farbcode\LaravelEvm\Events\TxFailed;
use Farbcode\LaravelEvm\Jobs\SendTransaction;
use Illuminate\Support\Facades\Event;

class FFailSigner implements Signer
{
    public function __construct(private string $pk) {}

    public function getAddress(): string
    {
        return '0xdeadbeefdeadbeefdeadbeefdeadbeefdeadbeef';
    }

    public function privateKey(): string
    {
        return $this->pk;
    }
}
class FFailNonce implements NonceManager
{
    public function getPendingNonce(string $address, callable $fetcher): int
    {
        return 1;
    }

    public function markUsed(string $address, int $nonce): void {}
}
class FFailFees implements FeePolicy
{
    public function suggest(callable $gasPriceFetcher): array
    {
        return [1_000_000_000, 50_000_000_000];
    }

    public function replace(int $oldPriority, int $oldMax): array
    {
        return [$oldPriority + 1_000_000_000, $oldMax + 10_000_000_000];
    }
}
class FFailRpc implements RpcClient
{
    public function call(string $method, array $params = []): mixed
    {
        if ($method === 'eth_estimateGas') {
            return '0x5208';
        }
        if ($method === 'eth_getTransactionCount') {
            return '0x1';
        }
        if ($method === 'eth_gasPrice') {
            return '0x3b9aca00';
        }
        if ($method === 'eth_getTransactionReceipt') {
            return [];
        }
        if ($method === 'eth_sendRawTransaction') {
            throw new RuntimeException('simulated failure');
        }

        return [];
    }

    public function callRaw(string $method, array $params = []): array
    {
        return [];
    }

    public function health(): array
    {
        return ['chainId' => 137, 'block' => 123];
    }
    public function getLogs(array $filter): array { return []; }
}

it('emits TxFailed on initial broadcast error', function () {
    Event::fake();
    $job = new SendTransaction(
        address: '0xContract',
        data: '0xabcdef',
        opts: ['timeout' => 1],
        chainId: 137,
        txCfg: [
            'estimate_padding' => 1.2,
            'confirm_timeout' => 0,
            'max_replacements' => 0,
            'poll_interval_ms' => 50,
            'queue' => 'evm-send',
        ]
    );
    $job->handle(new FFailRpc, new FFailSigner('0x'.str_repeat('aa', 32)), new FFailNonce, new FFailFees);
    Event::assertDispatched(TxFailed::class, function ($e) {
        return str_contains($e->reason, 'rpc_send_error');
    });
});
