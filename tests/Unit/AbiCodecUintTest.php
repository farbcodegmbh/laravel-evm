<?php

use Farbcode\LaravelEvm\Codec\AbiCodecWeb3p;

function transferAbi(string $amountType = 'uint256'): array
{
    return [[
        'type' => 'function',
        'name' => 'transfer',
        'inputs' => [
            ['name' => 'to', 'type' => 'address'],
            ['name' => 'amount', 'type' => $amountType],
        ],
    ]];
}

const RECIPIENT = '0x5aAeb6053F3E94C9b9A09f33669435E7Ef1BeAed';

it('encodes amounts beyond PHP_INT_MAX without clamping', function () {
    $data = new AbiCodecWeb3p()->encodeFunction(transferAbi(), 'transfer', [RECIPIENT, '10000000000000000000']);

    expect($data)->toEndWith('0000000000000000000000000000000000000000000000008ac7230489e80000');
    expect($data)->not->toContain('7fffffffffffffff');
});

it('encodes the maximum uint256 value', function () {
    $max = '115792089237316195423570985008687907853269984665640564039457584007913129639935';

    expect(new AbiCodecWeb3p()->encodeFunction(transferAbi(), 'transfer', [RECIPIENT, $max]))
        ->toEndWith(str_repeat('f', 64));
});

it('accepts 0x-prefixed hex amounts instead of silently encoding zero', function () {
    $data = new AbiCodecWeb3p()->encodeFunction(transferAbi(), 'transfer', [RECIPIENT, '0x0de0b6b3a7640000']);

    expect($data)->toEndWith('0000000000000000000000000000000000000000000000000de0b6b3a7640000');
});

it('accepts native integers', function () {
    expect(new AbiCodecWeb3p()->encodeFunction(transferAbi(), 'transfer', [RECIPIENT, 100]))
        ->toEndWith(str_pad('64', 64, '0', STR_PAD_LEFT));
});

it('rejects values that exceed the declared width', function () {
    expect(fn () => new AbiCodecWeb3p()->encodeFunction(transferAbi('uint8'), 'transfer', [RECIPIENT, 256]))
        ->toThrow(InvalidArgumentException::class);

    expect(fn () => new AbiCodecWeb3p()->encodeFunction(transferAbi(), 'transfer', [RECIPIENT, '-1']))
        ->toThrow(InvalidArgumentException::class);
});

it('rejects non numeric arguments instead of casting them to zero', function () {
    expect(fn () => new AbiCodecWeb3p()->encodeFunction(transferAbi(), 'transfer', [RECIPIENT, 'not-a-number']))
        ->toThrow(InvalidArgumentException::class);

    expect(fn () => new AbiCodecWeb3p()->encodeFunction(transferAbi(), 'transfer', [RECIPIENT, null]))
        ->toThrow(InvalidArgumentException::class);

    expect(fn () => new AbiCodecWeb3p()->encodeFunction(transferAbi(), 'transfer', [RECIPIENT, [1, 2, 3]]))
        ->toThrow(InvalidArgumentException::class);
});

it('keeps the function selector intact', function () {
    expect(new AbiCodecWeb3p()->encodeFunction(transferAbi(), 'transfer', [RECIPIENT, 1]))
        ->toStartWith('0xa9059cbb');
});
