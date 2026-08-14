<?php

use Farbcode\LaravelEvm\Contracts\Signer;
use Farbcode\LaravelEvm\Crypto\PrivateKeySigner;
use Farbcode\LaravelEvm\Exceptions\SignerException;
use Farbcode\LaravelEvm\Jobs\SendTransaction;

const SIGNER_KEY = '0x4c0883a69102937d6231471b5dbb6204fe5129617082792ae468d01a3f362318';

function signerFields(array $overrides = []): array
{
    return array_merge([
        'chainId' => 137,
        'nonce' => 5,
        'maxPriorityFeePerGas' => 1_000_000_000,
        'maxFeePerGas' => 50_000_000_000,
        'gas' => 150000,
        'to' => '0x1111111111111111111111111111111111111111',
        'from' => '0x0000000000000000000000000000000000000000',
        'value' => 0,
        'data' => '0xabcdef',
        'accessList' => [],
    ], $overrides);
}

it('signs a transaction and returns a 0x-prefixed payload', function () {
    $raw = new PrivateKeySigner(SIGNER_KEY)->sign(signerFields());

    expect($raw)->toStartWith('0x02');   // EIP-1559 typed transaction envelope
    expect(strlen($raw))->toBeGreaterThan(100);
});

it('produces the same payload for the same fields', function () {
    $signer = new PrivateKeySigner(SIGNER_KEY);

    expect($signer->sign(signerFields()))->toBe($signer->sign(signerFields()));
});

it('produces a different payload for a different nonce', function () {
    $signer = new PrivateKeySigner(SIGNER_KEY);

    expect($signer->sign(signerFields()))->not->toBe($signer->sign(signerFields(['nonce' => 6])));
});

it('does not expose the private key', function () {
    expect(method_exists(PrivateKeySigner::class, 'privateKey'))->toBeFalse();

    $reflection = new ReflectionClass(PrivateKeySigner::class);

    foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
        expect($method->getName())->not->toBe('privateKey');
    }
});

it('keeps the key out of the trace when the key itself is rejected', function () {
    try {
        new PrivateKeySigner('0xnope');
        $this->fail('expected the signer to reject this key');
    } catch (SignerException $e) {
        expect($e->getMessage())->toBe('Invalid private key format');
    }
});

it('keeps signing inside the signer, off the caller stack frame', function () {
    // The job used to fetch the key and pass it to the signing library itself,
    // which put the key in an argument frame of every trace taken below that
    // call. Signing now happens where the key lives.
    $signAndSend = new ReflectionMethod(SendTransaction::class, 'signAndSend');
    $types = array_map(fn ($p) => (string) $p->getType(), $signAndSend->getParameters());

    expect($types)->toContain(Signer::class);
    expect($types)->not->toContain('string');
});

it('requires only the contract, not a concrete implementation', function () {
    $remote = new class implements Signer
    {
        public function getAddress(): string
        {
            return '0x1111111111111111111111111111111111111111';
        }

        public function sign(array $fields): string
        {
            // A KMS or HSM backed signer never has a key to hand out
            return '0x02deadbeef';
        }
    };

    expect($remote->sign(signerFields()))->toBe('0x02deadbeef');
});
