<?php

use Farbcode\LaravelEvm\Support\Encoding;
use Farbcode\LaravelEvm\Support\Requirements;

it('passes when the extension is loaded', function () {
    Requirements::gmp();
})->throwsNoExceptions();

it('declares ext-gmp so composer refuses to install without it', function () {
    $composer = json_decode(file_get_contents(__DIR__.'/../../composer.json'), true);

    expect($composer['require'])->toHaveKey('ext-gmp');
});

it('does not guard in the service provider, where it would take down the whole app', function () {
    $provider = file_get_contents(__DIR__.'/../../src/LaravelEvmServiceProvider.php');

    // register() runs on every request and every artisan command
    expect($provider)->not->toContain('extension_loaded');
});

it('guards every code path that reaches gmp', function () {
    // Our own maths funnels through Encoding
    $encoding = new ReflectionClass(Encoding::class);
    $source = file_get_contents($encoding->getFileName());
    expect(substr_count($source, 'Requirements::gmp()'))->toBeGreaterThanOrEqual(2);

    // The signer reaches gmp_init() through kornrunner/ethereum-address instead
    $signer = file_get_contents(__DIR__.'/../../src/Crypto/PrivateKeySigner.php');
    expect($signer)->toContain('Requirements::gmp()');
});

it('checks only once per process', function () {
    $before = microtime(true);
    for ($i = 0; $i < 1000; $i++) {
        Requirements::gmp();
    }

    // A memoised check must not add measurable cost to hot encoding paths
    expect(microtime(true) - $before)->toBeLessThan(0.05);
});
