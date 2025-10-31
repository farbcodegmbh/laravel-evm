<?php

use Farbcode\LaravelEvm\Contracts\FeePolicy;
use Farbcode\LaravelEvm\Contracts\NonceManager;
use Farbcode\LaravelEvm\Contracts\RpcClient;
use Farbcode\LaravelEvm\Contracts\Signer;
use Illuminate\Support\Facades\Artisan;

class BumpSigner implements Signer
{
    public function getAddress(): string
    {
        return '0xdeadbeefdeadbeefdeadbeefdeadbeefdeadbeef';
    }

    public function privateKey(): string
    {
        return '0x'.str_repeat('11', 32);
    }
}
class BumpRpc implements RpcClient
{
    public array $sent = [];

    public function call(string $m, array $p = []): mixed
    {
        return match ($m) {
            'eth_getTransactionCount' => '0x5', // latest and pending both 0x5 -> no pending
            'eth_gasPrice' => '0x3b9aca00',
            'eth_chainId' => '0x89', // 137
            'eth_sendRawTransaction' => ($this->sent[] = $p[0]) && '0xhash123',
            default => '0x1'
        };
    }

    public function callRaw(string $m, array $p = []): array
    {
        return ['result' => '0x1'];
    }

    public function health(): array
    {
        return ['chainId' => 137, 'block' => 1];
    }

    public function getLogs(array $filter): array
    {
        return [];
    }
}
class BumpFees implements FeePolicy
{
    public function suggest(callable $f): array
    {
        return [1_000_000_000, 50_000_000_000];
    }

    public function replace(int $a, int $b): array
    {
        return [$a * 2, $b * 2];
    }
}
class BumpNonce implements NonceManager
{
    public function getPendingNonce(string $a, callable $b): int
    {
        return 5;
    }

    public function markUsed(string $a, int $n): void {}
}

it('fails gracefully when no pending nonce', function () {
    app()->bind(Signer::class, fn () => new BumpSigner);
    app()->bind(RpcClient::class, fn () => new BumpRpc);
    app()->bind(FeePolicy::class, fn () => new BumpFees);
    app()->bind(NonceManager::class, fn () => new BumpNonce);
    $code = Artisan::call('evm:bump');
    expect($code)->toBe(1); // FAILURE
});

it('broadcasts when explicit nonce provided', function () {
    app()->bind(Signer::class, fn () => new BumpSigner);
    app()->bind(RpcClient::class, fn () => new BumpRpc);
    app()->bind(FeePolicy::class, fn () => new BumpFees);
    app()->bind(NonceManager::class, fn () => new BumpNonce);
    $code = Artisan::call('evm:bump', ['--nonce' => 4, '--dry-run' => true]);
    expect($code)->toBe(0);
});
