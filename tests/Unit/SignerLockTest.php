<?php

use Farbcode\LaravelEvm\Contracts\Signer;
use Farbcode\LaravelEvm\Jobs\SendTransaction;
use Illuminate\Queue\Middleware\WithoutOverlapping;

function lockJob(): SendTransaction
{
    return new SendTransaction(
        address: '0x1111111111111111111111111111111111111111',
        data: '0xabcdef',
        opts: [],
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

beforeEach(function () {
    app()->instance(Signer::class, new ReceiptSigner);
});

it('serialises transactions per signing address', function () {
    $middleware = lockJob()->middleware();

    expect($middleware)->toHaveCount(1);
    expect($middleware[0])->toBeInstanceOf(WithoutOverlapping::class);
});

it('keys the lock on the signer, not on the contract', function () {
    $middleware = lockJob()->middleware()[0];
    $address = app(Signer::class)->getAddress();

    expect($middleware->key)->toBe('laravel-evm:signer:'.strtolower($address));
});

it('uses the same key for two transactions to different contracts', function () {
    $a = lockJob()->middleware()[0];

    $b = new SendTransaction(
        address: '0x2222222222222222222222222222222222222222',
        data: '0xdeadbeef',
        opts: [],
        chainId: 137,
        txCfg: ['confirm_timeout' => 120, 'max_replacements' => 2],
    )->middleware()[0];

    expect($a->key)->toBe($b->key);
});

it('holds the lock longer than the job can run', function () {
    $job = lockJob();
    $middleware = $job->middleware()[0];

    // A killed worker must not leave the next transaction free to race it
    expect($middleware->expiresAfter)->toBeGreaterThan($job->timeout);
});

it('releases rather than dropping a job that finds the lock taken', function () {
    $middleware = lockJob()->middleware()[0];

    // dontRelease() would silently discard a transaction the caller queued
    expect($middleware->releaseAfter)->toBeGreaterThan(0);
});

it('allows enough attempts for a release to be picked up again', function () {
    $job = lockJob();

    // maxTries is not set; the lifetime bound is what limits the job
    expect($job->retryUntil()->getTimestamp())->toBeGreaterThan(now()->getTimestamp());
    expect(property_exists($job, 'tries'))->toBeFalse();
});
