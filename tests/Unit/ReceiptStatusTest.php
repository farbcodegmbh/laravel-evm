<?php

use Farbcode\LaravelEvm\Contracts\FeePolicy;
use Farbcode\LaravelEvm\Contracts\NonceManager;
use Farbcode\LaravelEvm\Contracts\RpcClient;
use Farbcode\LaravelEvm\Contracts\Signer;
use Farbcode\LaravelEvm\Events\TxMined;
use Farbcode\LaravelEvm\Events\TxReverted;
use Farbcode\LaravelEvm\Jobs\SendTransaction;
use Farbcode\LaravelEvm\Support\Receipt;
use Illuminate\Support\Facades\Event;
use kornrunner\Ethereum\Address;

const RECEIPT_TEST_KEY = '0x4c0883a69102937d6231471b5dbb6204fe5129617082792ae468d01a3f362318';

class ReceiptSigner implements Signer
{
    public function getAddress(): string
    {
        return '0x'.new Address(substr(RECEIPT_TEST_KEY, 2))->get();
    }

    public function privateKey(): string
    {
        return RECEIPT_TEST_KEY;
    }
}

class ReceiptNonce implements NonceManager
{
    public function getPendingNonce(string $address, callable $fetcher): int
    {
        return 1;
    }

    public function markUsed(string $address, int $nonce): void {}

    public function invalidate(string $address): void {}
}

class ReceiptFees implements FeePolicy
{
    public function suggest(callable $gasPriceFetcher): array
    {
        return [1_000_000_000, 50_000_000_000];
    }

    public function replace(int $oldPriority, int $oldMax): array
    {
        return [$oldPriority * 2, $oldMax * 2];
    }
}

class ReceiptRpc implements RpcClient
{
    public function __construct(private array $receipt) {}

    public function call(string $method, array $params = []): mixed
    {
        return match ($method) {
            'eth_estimateGas' => '0x5208',
            'eth_getTransactionCount' => '0x1',
            'eth_gasPrice' => '0x2540be400',
            'eth_sendRawTransaction' => '0xdeadbeef',
            'eth_getTransactionReceipt' => $this->receipt,
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

function runReceiptJob(array $receipt): void
{
    new SendTransaction(
        address: '0x1111111111111111111111111111111111111111',
        data: '0xabcdef',
        opts: ['timeout' => 5, 'poll_ms' => 1, 'max_replacements' => 0],
        chainId: 137,
        txCfg: [
            'estimate_padding' => 1.2,
            'confirm_timeout' => 5,
            'max_replacements' => 0,
            'poll_interval_ms' => 1,
            'queue' => 'evm-send',
        ],
    )->handle(new ReceiptRpc($receipt), new ReceiptSigner, new ReceiptNonce, new ReceiptFees);
}

it('emits TxReverted instead of TxMined when the receipt reports a revert', function () {
    Event::fake();

    runReceiptJob(['status' => '0x0', 'transactionHash' => '0xdeadbeef']);

    Event::assertDispatched(TxReverted::class);
    Event::assertNotDispatched(TxMined::class);
});

it('emits TxMined for a successful receipt', function () {
    Event::fake();

    runReceiptJob(['status' => '0x1', 'transactionHash' => '0xdeadbeef']);

    Event::assertDispatched(TxMined::class);
    Event::assertNotDispatched(TxReverted::class);
});

it('treats a receipt without a status field as successful', function () {
    Event::fake();

    runReceiptJob(['transactionHash' => '0xdeadbeef']);

    Event::assertDispatched(TxMined::class);
    Event::assertNotDispatched(TxReverted::class);
});

class ReplacementReceiptRpc implements RpcClient
{
    private int $sends = 0;

    public function __construct(private array $receipt) {}

    public function call(string $method, array $params = []): mixed
    {
        return match ($method) {
            'eth_estimateGas' => '0x5208',
            'eth_getTransactionCount' => '0x1',
            'eth_gasPrice' => '0x2540be400',
            'eth_sendRawTransaction' => '0xreplaced'.(++$this->sends),
            // Only the replacement transaction gets a receipt.
            'eth_getTransactionReceipt' => $this->sends >= 2 ? $this->receipt : [],
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

it('also checks the receipt status on the replacement path', function () {
    Event::fake();

    new SendTransaction(
        address: '0x1111111111111111111111111111111111111111',
        data: '0xabcdef',
        opts: ['timeout' => 1, 'poll_ms' => 5, 'max_replacements' => 1],
        chainId: 137,
        txCfg: [
            'estimate_padding' => 1.2,
            'confirm_timeout' => 1,
            'max_replacements' => 1,
            'poll_interval_ms' => 5,
            'queue' => 'evm-send',
        ],
    )->handle(
        new ReplacementReceiptRpc(['status' => '0x0']),
        new ReceiptSigner,
        new ReceiptNonce,
        new ReceiptFees
    );

    Event::assertDispatched(TxReverted::class);
    Event::assertNotDispatched(TxMined::class);
});

it('reads the receipt status in every notation', function () {
    expect(Receipt::isReverted(['status' => '0x0']))->toBeTrue();
    expect(Receipt::isReverted(['status' => '0x00']))->toBeTrue();
    expect(Receipt::isReverted(['status' => 0]))->toBeTrue();
    expect(Receipt::isReverted(['status' => '0x1']))->toBeFalse();
    expect(Receipt::isReverted(['status' => 1]))->toBeFalse();

    expect(Receipt::isSuccessful(['status' => '0x1']))->toBeTrue();
    expect(Receipt::isSuccessful(['status' => '0x0']))->toBeFalse();
    expect(Receipt::isSuccessful(null))->toBeFalse();
    expect(Receipt::status(['transactionHash' => '0x1']))->toBeNull();
});
