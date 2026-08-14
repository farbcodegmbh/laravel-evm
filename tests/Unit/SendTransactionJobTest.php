<?php

use Farbcode\LaravelEvm\Contracts\RpcClient;
use Farbcode\LaravelEvm\Crypto\LocalNonceManager;
use Farbcode\LaravelEvm\Events\TxBroadcasted;
use Farbcode\LaravelEvm\Events\TxMined;
use Farbcode\LaravelEvm\Events\TxReplaced;
use Farbcode\LaravelEvm\Jobs\SendTransaction;
use Illuminate\Support\Facades\Event;

/**
 * Never produces a receipt until the given number of broadcasts happened, which
 * forces the job through the fee bump path.
 */
class ReplacementRpc implements RpcClient
{
    public int $sends = 0;

    public array $rawPayloads = [];

    public function __construct(private int $mineAfterSends) {}

    public function call(string $method, array $params = []): mixed
    {
        if ($method === 'eth_sendRawTransaction') {
            $this->sends++;
            $this->rawPayloads[] = $params[0];

            return '0xattempt'.$this->sends;
        }

        if ($method === 'eth_getTransactionReceipt') {
            return $this->sends >= $this->mineAfterSends
                ? ['status' => '0x1', 'transactionHash' => $params[0]]
                : [];
        }

        return match ($method) {
            'eth_estimateGas' => '0x5208',
            'eth_getTransactionCount' => '0x5',
            'eth_gasPrice' => '0x2540be400',
            'eth_maxPriorityFeePerGas' => '0x3b9aca00',
            'eth_getBlockByNumber' => ['baseFeePerGas' => '0x4a817c800'],
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

function replacementJob(int $maxReplacements): SendTransaction
{
    return new SendTransaction(
        address: '0x1111111111111111111111111111111111111111',
        data: '0xabcdef',
        opts: ['timeout' => 1, 'poll_ms' => 5, 'max_replacements' => $maxReplacements],
        chainId: 137,
        txCfg: [
            'estimate_padding' => 1.2,
            'confirm_timeout' => 1,
            'max_replacements' => $maxReplacements,
            'poll_interval_ms' => 5,
            'queue' => 'evm-send',
        ],
    );
}

it('replaces a pending transaction and eventually mines', function () {
    Event::fake();
    $rpc = new ReplacementRpc(mineAfterSends: 3);

    replacementJob(3)->handle($rpc, new ReceiptSigner, new ReceiptNonce, new ReceiptFees);

    Event::assertDispatchedTimes(TxBroadcasted::class, 3);
    Event::assertDispatchedTimes(TxReplaced::class, 2);
    Event::assertDispatched(TxMined::class);
});

it('numbers the replacement attempts from one', function () {
    Event::fake();

    replacementJob(2)->handle(new ReplacementRpc(mineAfterSends: 3), new ReceiptSigner, new ReceiptNonce, new ReceiptFees);

    $attempts = collect(Event::dispatched(TxReplaced::class))->map(fn ($e) => $e[0]->attempt)->all();

    expect($attempts)->toBe([1, 2]);
});

it('raises both fee caps on every replacement', function () {
    Event::fake();

    replacementJob(2)->handle(new ReplacementRpc(mineAfterSends: 3), new ReceiptSigner, new ReceiptNonce, new ReceiptFees);

    $fields = collect(Event::dispatched(TxReplaced::class))->map(fn ($e) => $e[0]->newFields)->all();

    expect($fields[1]['maxPriorityFeePerGas'])->toBeGreaterThan($fields[0]['maxPriorityFeePerGas']);
    expect($fields[1]['maxFeePerGas'])->toBeGreaterThan($fields[0]['maxFeePerGas']);
});

it('keeps the nonce identical across replacements', function () {
    Event::fake();

    // The real manager, so the nonce comes from eth_getTransactionCount (0x5)
    replacementJob(2)->handle(new ReplacementRpc(mineAfterSends: 3), new ReceiptSigner, new LocalNonceManager, new ReceiptFees);

    $nonces = collect(Event::dispatched(TxBroadcasted::class))->map(fn ($e) => $e[0]->fields['nonce'])->unique()->all();

    // A replacement that changes the nonce is not a replacement, it is a second
    // transaction, and both would be mined.
    expect($nonces)->toHaveCount(1);
    expect(array_values($nonces)[0])->toBe(5);
});

it('signs a distinct payload for each attempt', function () {
    Event::fake();
    $rpc = new ReplacementRpc(mineAfterSends: 3);

    replacementJob(2)->handle($rpc, new ReceiptSigner, new ReceiptNonce, new ReceiptFees);

    expect($rpc->rawPayloads)->toHaveCount(3);
    expect(array_unique($rpc->rawPayloads))->toHaveCount(3);
});

it('stops replacing once the limit is reached', function () {
    Event::fake();
    $rpc = new ReplacementRpc(mineAfterSends: 99);

    replacementJob(2)->handle($rpc, new ReceiptSigner, new ReceiptNonce, new ReceiptFees);

    // one original plus two replacements
    expect($rpc->sends)->toBe(3);
});
