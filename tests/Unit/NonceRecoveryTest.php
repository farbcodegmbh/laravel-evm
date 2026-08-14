<?php

use Farbcode\LaravelEvm\Contracts\NonceManager;
use Farbcode\LaravelEvm\Contracts\RpcClient;
use Farbcode\LaravelEvm\Crypto\LocalNonceManager;
use Farbcode\LaravelEvm\Jobs\SendTransaction;
use Illuminate\Support\Facades\Event;

/**
 * Records invalidate() calls and serves a chain nonce that can move underneath.
 */
class RecordingNonceManager implements NonceManager
{
    public array $invalidated = [];

    public function __construct(private LocalNonceManager $inner = new LocalNonceManager) {}

    public function getPendingNonce(string $address, callable $fetcher): int
    {
        return $this->inner->getPendingNonce($address, $fetcher);
    }

    public function markUsed(string $address, int $nonce): void
    {
        $this->inner->markUsed($address, $nonce);
    }

    public function invalidate(string $address): void
    {
        $this->invalidated[] = $address;
        $this->inner->invalidate($address);
    }
}

class SendFailsRpc implements RpcClient
{
    public function call(string $method, array $params = []): mixed
    {
        if ($method === 'eth_sendRawTransaction') {
            throw new RuntimeException('All RPC endpoints failed. Error: already known');
        }

        return match ($method) {
            'eth_estimateGas' => '0x5208',
            'eth_getTransactionCount' => '0x5',
            'eth_gasPrice' => '0x2540be400',
            default => [],
        };
    }

    public function callRaw(string $method, array $params = []): array
    {
        return [];
    }

    public function health(): array
    {
        return [];
    }

    public function getLogs(array $filter): array
    {
        return [];
    }
}

/**
 * Broadcasts fine, never produces a receipt, and the chain never advances.
 */
class DroppedTxRpc implements RpcClient
{
    public function call(string $method, array $params = []): mixed
    {
        return match ($method) {
            'eth_estimateGas' => '0x5208',
            // Both 'pending' and 'latest' stay at 5: nothing was ever mined.
            'eth_getTransactionCount' => '0x5',
            'eth_gasPrice' => '0x2540be400',
            'eth_sendRawTransaction' => '0xdropped',
            'eth_getTransactionReceipt' => [],
            default => [],
        };
    }

    public function callRaw(string $method, array $params = []): array
    {
        return [];
    }

    public function health(): array
    {
        return [];
    }

    public function getLogs(array $filter): array
    {
        return [];
    }
}

function recoveryJob(): SendTransaction
{
    return new SendTransaction(
        address: '0x1111111111111111111111111111111111111111',
        data: '0xabcdef',
        opts: ['timeout' => 0, 'poll_ms' => 1, 'max_replacements' => 0],
        chainId: 137,
        txCfg: [
            'estimate_padding' => 1.2,
            'confirm_timeout' => 0,
            'max_replacements' => 0,
            'poll_interval_ms' => 1,
            'queue' => 'evm-send',
        ],
    );
}

it('caches the nonce until it is marked used', function () {
    $manager = new LocalNonceManager;

    expect($manager->getPendingNonce('0xABC', fn () => 5))->toBe(5);
    expect($manager->getPendingNonce('0xABC', fn () => 99))->toBe(5);

    $manager->markUsed('0xABC', 5);

    expect($manager->getPendingNonce('0xABC', fn () => 99))->toBe(6);
});

it('re-reads from the chain after being invalidated', function () {
    $manager = new LocalNonceManager;

    $manager->getPendingNonce('0xABC', fn () => 5);
    $manager->invalidate('0xABC');

    expect($manager->getPendingNonce('0xABC', fn () => 99))->toBe(99);
});

it('treats the address case-insensitively when invalidating', function () {
    $manager = new LocalNonceManager;

    $manager->getPendingNonce('0xABC', fn () => 5);
    $manager->invalidate('0xabc');

    expect($manager->getPendingNonce('0xABC', fn () => 99))->toBe(99);
});

it('invalidates the cache when the broadcast fails', function () {
    Event::fake();
    $nonces = new RecordingNonceManager;

    recoveryJob()->handle(new SendFailsRpc, new ReceiptSigner, $nonces, new ReceiptFees);

    expect($nonces->invalidated)->not->toBeEmpty();
});

it('invalidates the cache when no transaction for the nonce was ever mined', function () {
    Event::fake();
    $nonces = new RecordingNonceManager;

    recoveryJob()->handle(new DroppedTxRpc, new ReceiptSigner, $nonces, new ReceiptFees);

    // markUsed() moved the cache to 6 while the chain is still at 5; without
    // invalidating, every later transaction would sit behind a nonce gap.
    expect($nonces->invalidated)->not->toBeEmpty();
});

it('keeps the cache when the nonce was consumed on chain', function () {
    Event::fake();
    $nonces = new RecordingNonceManager;

    $rpc = new class implements RpcClient
    {
        public function call(string $method, array $params = []): mixed
        {
            return match ($method) {
                'eth_estimateGas' => '0x5208',
                // pending 5, but latest already moved to 6: the nonce was used.
                'eth_getTransactionCount' => ($params[1] ?? 'pending') === 'latest' ? '0x6' : '0x5',
                'eth_gasPrice' => '0x2540be400',
                'eth_sendRawTransaction' => '0xmined',
                'eth_getTransactionReceipt' => [],
                default => [],
            };
        }

        public function callRaw(string $method, array $params = []): array
        {
            return [];
        }

        public function health(): array
        {
            return [];
        }

        public function getLogs(array $filter): array
        {
            return [];
        }
    };

    recoveryJob()->handle($rpc, new ReceiptSigner, $nonces, new ReceiptFees);

    expect($nonces->invalidated)->toBeEmpty();
});
