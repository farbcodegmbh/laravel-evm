<?php

use Farbcode\LaravelEvm\Contracts\RpcClient;
use Farbcode\LaravelEvm\Crypto\PrivateKeySigner;
use Farbcode\LaravelEvm\Support\Encoding;
use Farbcode\LaravelEvm\Support\LogFilterBuilder;
use kornrunner\Ethereum\Address;

it('derives an address from a private key with leading zero nibbles', function () {
    $signer = new PrivateKeySigner('0x0000000000000000000000000000000000000000000000000000000000001234');

    expect($signer->getAddress())->toMatch('/^0x[0-9a-f]{40}$/');
});

it('derives the same address whichever way the prefix is written', function () {
    $key = '0x4c0883a69102937d6231471b5dbb6204fe5129617082792ae468d01a3f362318';

    expect(new PrivateKeySigner($key)->getAddress())
        ->toBe('0x'.new Address(substr($key, 2))->get());
});

it('checksums addresses that begin with a zero nibble without truncating them', function () {
    $address = '0x00a5b2c3d4e5f60718293a4b5c6d7e8f90a1b2c3';
    $checksummed = Encoding::toChecksumAddress($address);

    expect(strlen($checksummed))->toBe(42);
    expect(strtolower($checksummed))->toBe($address);
});

it('matches the EIP-55 reference vectors', function (string $expected) {
    expect(Encoding::toChecksumAddress(strtolower($expected)))->toBe($expected);
    expect(Encoding::toChecksumAddress($expected))->toBe($expected);
})->with([
    '0x5aAeb6053F3E94C9b9A09f33669435E7Ef1BeAed',
    '0xfB6916095ca1df60bB79Ce92cE3Ea74c37c5d359',
    '0xdbF03B407c01E7cD3CBea99509d93f8DDDC8C6FB',
    '0xD1220A0cf47c7B9Be7A2E6BA89F429762e7b9aDb',
]);

it('rejects addresses of the wrong length instead of returning a shortened one', function () {
    expect(fn () => Encoding::toChecksumAddress('0xabc'))
        ->toThrow(InvalidArgumentException::class);

    expect(fn () => Encoding::toChecksumAddress('0x'.str_repeat('z', 40)))
        ->toThrow(InvalidArgumentException::class);
});

it('keeps leading zeros when normalizing a topic without a prefix', function () {
    $topic = str_pad('00ab12', 64, '0', STR_PAD_LEFT);

    $filter = LogFilterBuilder::make(new class implements RpcClient
    {
        public function call(string $method, array $params = []): mixed
        {
            return null;
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
    })->fromBlock(1)->toBlock(2)->topic(0, $topic)->build();

    expect($filter['topics'][0])->toBe('0x'.$topic);
});
