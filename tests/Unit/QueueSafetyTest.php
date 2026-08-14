<?php

use Farbcode\LaravelEvm\Contracts\RpcClient;
use Farbcode\LaravelEvm\Events\TxFailed;
use Farbcode\LaravelEvm\Events\TxMined;
use Farbcode\LaravelEvm\Jobs\SendTransaction;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Support\Facades\Event;

/**
 * Receipt lookups throw until the configured number of failures is exhausted.
 */
class FlakyReceiptRpc implements RpcClient
{
    private int $failures = 0;

    private int $sends = 0;

    public function __construct(private int $failUntil) {}

    public function call(string $method, array $params = []): mixed
    {
        if ($method === 'eth_getTransactionReceipt') {
            if ($this->failures < $this->failUntil) {
                $this->failures++;

                throw new RuntimeException('All RPC endpoints failed. Error: HTTP 502');
            }

            return ['status' => '0x1', 'transactionHash' => $params[0]];
        }

        return match ($method) {
            'eth_estimateGas' => '0x5208',
            'eth_getTransactionCount' => '0x1',
            'eth_gasPrice' => '0x2540be400',
            'eth_sendRawTransaction' => '0xsend'.(++$this->sends),
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
 * The original transaction is mined, but only after a replacement went out.
 */
class OriginalWinsRpc implements RpcClient
{
    private int $sends = 0;

    public array $receiptLookups = [];

    public function call(string $method, array $params = []): mixed
    {
        if ($method === 'eth_getTransactionReceipt') {
            $this->receiptLookups[] = $params[0];

            // The first transaction gets its receipt once a replacement exists.
            return $params[0] === '0xsend1' && $this->sends >= 2
                ? ['status' => '0x1', 'transactionHash' => '0xsend1']
                : [];
        }

        return match ($method) {
            'eth_estimateGas' => '0x5208',
            'eth_getTransactionCount' => '0x1',
            'eth_gasPrice' => '0x2540be400',
            'eth_sendRawTransaction' => '0xsend'.(++$this->sends),
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

function safetyJob(array $opts = []): SendTransaction
{
    return new SendTransaction(
        address: '0x1111111111111111111111111111111111111111',
        data: '0xabcdef',
        opts: $opts,
        chainId: 137,
        txCfg: [
            'estimate_padding' => 1.2,
            'confirm_timeout' => 120,
            'max_replacements' => 2,
            'poll_interval_ms' => 800,
            'queue' => 'evm-send',
        ],
    );
}

it('never retries, because a second attempt would broadcast a second transaction', function () {
    expect(safetyJob()->tries)->toBe(1);
});

it('claims a worker timeout that covers every replacement window', function () {
    // 120s initial window + 2 replacement windows of 120s + slack
    expect(safetyJob()->timeout)->toBe(390);

    expect(safetyJob(['timeout' => 30, 'max_replacements' => 1])->timeout)->toBe(90);
});

it('waits for the surrounding database transaction to commit', function () {
    expect(safetyJob())->toBeInstanceOf(ShouldQueueAfterCommit::class);
});

it('keeps polling when a receipt lookup fails transiently', function () {
    Event::fake();

    safetyJob(['timeout' => 5, 'poll_ms' => 1, 'max_replacements' => 0])
        ->handle(new FlakyReceiptRpc(3), new ReceiptSigner, new ReceiptNonce, new ReceiptFees);

    Event::assertDispatched(TxMined::class);
    Event::assertNotDispatched(TxFailed::class);
});

it('still reports the original transaction when it wins the race against a replacement', function () {
    Event::fake();

    $rpc = new OriginalWinsRpc;

    safetyJob(['timeout' => 1, 'poll_ms' => 5, 'max_replacements' => 1])
        ->handle($rpc, new ReceiptSigner, new ReceiptNonce, new ReceiptFees);

    // The original only gets a receipt once a replacement exists, so reporting
    // it proves the earlier hash is still polled after the replacement went out.
    Event::assertDispatched(TxMined::class, fn (TxMined $e) => $e->txHash === '0xsend1');
    Event::assertNotDispatched(TxFailed::class);

    expect($rpc->receiptLookups)->toContain('0xsend1');
});

it('reports a terminal failure through TxFailed', function () {
    Event::fake();

    safetyJob(['timeout' => 0, 'poll_ms' => 1, 'max_replacements' => 0])
        ->handle(new ReceiptRpc([]), new ReceiptSigner, new ReceiptNonce, new ReceiptFees);

    Event::assertDispatched(TxFailed::class);
});
