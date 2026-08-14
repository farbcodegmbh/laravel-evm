<?php

use Illuminate\Support\Facades\Artisan;

function generateAddresses(int $count): array
{
    expect(Artisan::call('evm:address:generate', ['--count' => $count, '--json' => true]))->toBe(0);

    return json_decode(Artisan::output(), true);
}

it('generates a well formed address', function () {
    $rows = generateAddresses(1);

    expect($rows)->toBeArray()->toHaveCount(1);
    expect($rows[0]['address'])->toMatch('/^0x[0-9a-fA-F]{40}$/');
    expect($rows[0]['private_key'])->toMatch('/^0x[0-9a-f]{64}$/');
    // The library returns the 64 byte uncompressed key without the 0x04 prefix
    expect($rows[0]['public_key'])->toMatch('/^0x[0-9a-f]{128}$/');
});

it('always emits 40 hex characters, including for addresses starting with zero', function () {
    // The checksum used to truncate any address whose first nibble was 0, which
    // is roughly one in sixteen - so a single address is not enough to catch it
    foreach (generateAddresses(32) as $row) {
        expect(strlen($row['address']))->toBe(42);
        expect(substr($row['address'], 2))->toMatch('/^[0-9a-fA-F]{40}$/');
    }
});

it('generates distinct keys', function () {
    $rows = generateAddresses(8);

    expect(array_unique(array_column($rows, 'private_key')))->toHaveCount(8);
    expect(array_unique(array_column($rows, 'address')))->toHaveCount(8);
});

it('derives the address from the private key it printed', function () {
    $row = generateAddresses(1)[0];

    $derived = '0x'.new kornrunner\Ethereum\Address(substr($row['private_key'], 2))->get();

    expect(strtolower($row['address']))->toBe($derived);
});

it('refuses a count outside the supported range', function () {
    expect(Artisan::call('evm:address:generate', ['--count' => 0]))->toBe(1);
    expect(Artisan::call('evm:address:generate', ['--count' => 51]))->toBe(1);
});
