<?php

use Farbcode\LaravelEvm\Contracts\RpcClient;
use Farbcode\LaravelEvm\Support\FeeSnapshot;
use Farbcode\LaravelEvm\Support\SimpleFeePolicy;

const GWEI = 1_000_000_000;

function feePolicy(array $cfg = []): SimpleFeePolicy
{
    return new SimpleFeePolicy(array_merge([
        'min_priority_gwei' => 1,
        'min_maxfee_gwei' => 0,
        'base_multiplier' => 2,
        'replacement_factor' => 1.5,
    ], $cfg));
}

it('derives the cap from the base fee and the reported tip', function () {
    // base fee 20 gwei, tip 2 gwei, multiplier 2 -> 42 gwei
    [$tip, $maxFee] = feePolicy()->suggest(new FeeSnapshot(
        baseFeePerGas: (string) (20 * GWEI),
        priorityFeePerGas: (string) (2 * GWEI),
        gasPrice: (string) (22 * GWEI),
    ));

    expect($tip)->toBe(2 * GWEI);
    expect($maxFee)->toBe(42 * GWEI);
});

it('prefers the reported tip over a share of the legacy gas price', function () {
    [$tip] = feePolicy()->suggest(new FeeSnapshot(
        baseFeePerGas: (string) (20 * GWEI),
        priorityFeePerGas: (string) (2 * GWEI),
        gasPrice: (string) (500 * GWEI),
    ));

    // 10% of gasPrice would have been 50 gwei
    expect($tip)->toBe(2 * GWEI);
});

it('falls back to the legacy gas price when the node reports no tip', function () {
    [$tip, $maxFee] = feePolicy()->suggest(new FeeSnapshot(
        baseFeePerGas: null,
        priorityFeePerGas: null,
        gasPrice: (string) (30 * GWEI),
    ));

    expect($tip)->toBe(3 * GWEI);
    expect($maxFee)->toBe(60 * GWEI);
});

it('treats the configured gwei values as floors', function () {
    [$tip, $maxFee] = feePolicy(['min_priority_gwei' => 30, 'min_maxfee_gwei' => 100])
        ->suggest(new FeeSnapshot(
            baseFeePerGas: (string) GWEI,
            priorityFeePerGas: (string) GWEI,
            gasPrice: (string) GWEI,
        ));

    expect($tip)->toBe(30 * GWEI);
    expect($maxFee)->toBe(100 * GWEI);
});

it('never returns a cap below the tip', function () {
    // A floor configured above the cap used to produce an unsendable pair
    [$tip, $maxFee] = feePolicy(['min_priority_gwei' => 200, 'min_maxfee_gwei' => 10])
        ->suggest(new FeeSnapshot(baseFeePerGas: (string) GWEI, priorityFeePerGas: (string) GWEI));

    expect($maxFee)->toBeGreaterThanOrEqual($tip);
});

it('survives a snapshot with nothing in it', function () {
    [$tip, $maxFee] = feePolicy()->suggest(new FeeSnapshot);

    expect($tip)->toBe(GWEI);
    expect($maxFee)->toBeGreaterThanOrEqual($tip);
});

it('raises both caps by at least the ten percent nodes require', function () {
    [$tip, $maxFee] = feePolicy()->replace(10 * GWEI, 100 * GWEI);

    expect($tip)->toBeGreaterThanOrEqual((int) (10 * GWEI * 1.1));
    expect($maxFee)->toBeGreaterThanOrEqual((int) (100 * GWEI * 1.1));
});

it('bumps far enough even when the configured factor is too small', function () {
    [$tip, $maxFee] = feePolicy(['replacement_factor' => 1.01])->replace(100 * GWEI, 200 * GWEI);

    expect($tip)->toBeGreaterThanOrEqual((int) (100 * GWEI * 1.1));
    expect($maxFee)->toBeGreaterThanOrEqual((int) (200 * GWEI * 1.1));
});

it('keeps the cap above the tip after a replacement', function () {
    [$tip, $maxFee] = feePolicy(['replacement_factor' => 3.0])->replace(50 * GWEI, 55 * GWEI);

    expect($maxFee)->toBeGreaterThanOrEqual($tip);
});

it('reads a snapshot off the chain, tolerating unsupported methods', function () {
    $rpc = new class implements RpcClient
    {
        public function call(string $method, array $params = []): mixed
        {
            return match ($method) {
                'eth_getBlockByNumber' => ['baseFeePerGas' => '0x4a817c800'],  // 20 gwei
                // Plenty of providers do not implement this one
                'eth_maxPriorityFeePerGas' => throw new RuntimeException('method not found'),
                'eth_gasPrice' => '0x51f4d5c00',                               // 22 gwei
                default => null,
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

    $snapshot = FeeSnapshot::fromRpc($rpc);

    expect($snapshot->baseFeePerGas)->toBe((string) (20 * GWEI));
    expect($snapshot->priorityFeePerGas)->toBeNull();
    expect($snapshot->gasPrice)->toBe((string) (22 * GWEI));
});
