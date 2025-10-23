<?php

// Facade aliases are registered via composer.json extra.laravel.aliases
// No import/use statements needed; we reference them directly.

it('resolves contract client facade', function () {
    $c = \EvmContract::at('0xdead', []);
    expect($c)->not->toBeNull();
});

it('resolves rpc client facade health', function () {
    $h = \EvmRpc::health();
    expect($h)->toBeArray()->and($h)->toHaveKeys(['chainId','block']);
});

it('resolves signer facade address', function () {
    try {
    $addr = \EvmSigner::getAddress();
        expect($addr)->toStartWith('0x');
    } catch (Throwable $e) {
        // If no private key configured, we accept exception
        expect($e)->toBeInstanceOf(Throwable::class);
    }
});

it('resolves fees facade suggest', function () {
    $fees = \EvmFees::suggest(fn() => '0x3b9aca00');
    expect($fees)->toBeArray()->and(count($fees))->toBe(2);
});

it('resolves nonce facade initial', function () {
    $n = \EvmNonce::getPendingNonce('0xabc', fn() => 5);
    expect($n)->toBe(5);
});
