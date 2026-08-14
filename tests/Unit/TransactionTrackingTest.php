<?php

use Farbcode\LaravelEvm\Contracts\TransactionStore;
use Farbcode\LaravelEvm\Jobs\SendTransaction;
use Farbcode\LaravelEvm\Models\EvmTransaction;
use Farbcode\LaravelEvm\Support\EloquentTransactionStore;
use Farbcode\LaravelEvm\Support\NullTransactionStore;
use Illuminate\Support\Facades\Event;

class RecordingStore implements TransactionStore
{
    public array $records = [];

    public function record(string $requestId, array $attributes): void
    {
        $this->records[] = [$requestId, $attributes];
    }

    public function statuses(): array
    {
        return array_values(array_filter(array_map(fn ($r) => $r[1]['status'] ?? null, $this->records)));
    }
}

function trackedJob(array $opts = []): SendTransaction
{
    return new SendTransaction(
        address: '0x1111111111111111111111111111111111111111',
        data: '0xabcdef',
        opts: array_merge(['request_id' => 'req-1', 'timeout' => 5, 'poll_ms' => 1, 'max_replacements' => 0], $opts),
        chainId: 137,
        txCfg: ['estimate_padding' => 1.2, 'confirm_timeout' => 5, 'max_replacements' => 0, 'poll_interval_ms' => 1, 'queue' => 'evm-send'],
    );
}

it('records the lifecycle under the id sendAsync returned', function () {
    Event::fake();
    $store = new RecordingStore;

    trackedJob()->handle(
        new ReceiptRpc(['status' => '0x1', 'transactionHash' => '0xdeadbeef']),
        new ReceiptSigner, new ReceiptNonce, new ReceiptFees, $store
    );

    expect($store->records)->not->toBeEmpty();
    expect(array_unique(array_map(fn ($r) => $r[0], $store->records)))->toBe(['req-1']);
    expect($store->statuses())->toBe([
        EvmTransaction::STATUS_QUEUED,
        EvmTransaction::STATUS_BROADCAST,
        EvmTransaction::STATUS_MINED,
    ]);
});

it('records a revert as such', function () {
    Event::fake();
    $store = new RecordingStore;

    trackedJob()->handle(
        new ReceiptRpc(['status' => '0x0']),
        new ReceiptSigner, new ReceiptNonce, new ReceiptFees, $store
    );

    expect($store->statuses())->toContain(EvmTransaction::STATUS_REVERTED);
});

it('records a terminal failure with its reason', function () {
    Event::fake();
    $store = new RecordingStore;

    trackedJob(['timeout' => 0])->handle(
        new ReceiptRpc([]),
        new ReceiptSigner, new ReceiptNonce, new ReceiptFees, $store
    );

    $failed = array_values(array_filter($store->records, fn ($r) => ($r[1]['status'] ?? null) === EvmTransaction::STATUS_FAILED));

    expect($failed)->not->toBeEmpty();
    expect($failed[0][1]['reason'])->toContain('no_receipt');
});

it('keeps the signer, target and nonce on the record', function () {
    Event::fake();
    $store = new RecordingStore;

    trackedJob()->handle(
        new ReceiptRpc(['status' => '0x1']),
        new ReceiptSigner, new ReceiptNonce, new ReceiptFees, $store
    );

    $queued = $store->records[0][1];
    expect($queued['to'])->toBe('0x1111111111111111111111111111111111111111');
    expect($queued['signer'])->toStartWith('0x');

    $broadcast = $store->records[1][1];
    expect($broadcast['nonce'])->toBe(1);
    expect($broadcast['hashes'])->toBeArray();
});

it('runs without a store at all', function () {
    Event::fake();

    trackedJob()->handle(
        new ReceiptRpc(['status' => '0x1']),
        new ReceiptSigner, new ReceiptNonce, new ReceiptFees
    );
})->throwsNoExceptions();

it('records nothing when there is no request id', function () {
    Event::fake();
    $store = new RecordingStore;

    new SendTransaction(
        address: '0x1111111111111111111111111111111111111111',
        data: '0xabcdef',
        opts: ['timeout' => 5, 'poll_ms' => 1, 'max_replacements' => 0],
        chainId: 137,
        txCfg: ['confirm_timeout' => 5, 'max_replacements' => 0, 'poll_interval_ms' => 1],
    )->handle(new ReceiptRpc(['status' => '0x1']), new ReceiptSigner, new ReceiptNonce, new ReceiptFees, $store);

    expect($store->records)->toBeEmpty();
});

it('discards everything by default', function () {
    expect(app(TransactionStore::class))->toBeInstanceOf(NullTransactionStore::class);
});

it('uses the eloquent store when tracking is switched on', function () {
    config()->set('evm.tracking.enabled', true);
    app()->forgetInstance(TransactionStore::class);

    expect(app(TransactionStore::class))->toBeInstanceOf(EloquentTransactionStore::class);
});

it('does not let a bookkeeping failure take down the transaction', function () {
    // No migration has run here, so the write fails
    new EloquentTransactionStore()->record('req-1', ['status' => 'queued']);
})->throwsNoExceptions();
