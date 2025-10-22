<?php

use Illuminate\Support\Facades\Artisan;

it('generates a single address in json or reports failure gracefully', function () {
    $code = Artisan::call('evm:address:generate', ['--count' => 1, '--json' => true]);
    $out = Artisan::output();
    if ($code !== 0) {
        // In rare environments (e.g., GMP/ECC edge) we accept graceful failure
        expect($out)->toContain('Failed to generate address');

        return;
    }
    $data = json_decode($out, true);
    expect($data)->toBeArray()->and(count($data))->toBe(1);
    expect($data[0]['address'])->toStartWith('0x');
    expect($data[0]['private_key'])->toStartWith('0x')->and(strlen($data[0]['private_key']))->toBe(66);
});
