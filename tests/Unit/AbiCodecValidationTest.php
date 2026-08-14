<?php

use Farbcode\LaravelEvm\Codec\AbiCodecWeb3p;

function codec(): AbiCodecWeb3p
{
    return new AbiCodecWeb3p;
}

function fnAbi(string $name, array $types): array
{
    return [
        'type' => 'function',
        'name' => $name,
        'inputs' => array_map(fn ($t, $i) => ['name' => 'a'.$i, 'type' => $t], $types, array_keys($types)),
    ];
}

const ADDR = '0x5aAeb6053F3E94C9b9A09f33669435E7Ef1BeAed';

// --- unsupported types fail loudly ------------------------------------------

it('rejects array types instead of encoding them as the number one', function () {
    expect(fn () => codec()->encodeFunction([fnAbi('batch', ['uint256[]'])], 'batch', [[1, 2, 3]]))
        ->toThrow(RuntimeException::class);

    expect(fn () => codec()->encodeFunction([fnAbi('batch', ['address[]'])], 'batch', [[ADDR]]))
        ->toThrow(RuntimeException::class);
});

it('rejects tuple types', function () {
    expect(fn () => codec()->encodeFunction([fnAbi('f', ['tuple'])], 'f', [[1]]))
        ->toThrow(RuntimeException::class);
});

// --- argument count ----------------------------------------------------------

it('rejects a call with the wrong number of arguments', function () {
    expect(fn () => codec()->encodeFunction([fnAbi('f', ['uint256', 'uint256'])], 'f', [1]))
        ->toThrow(InvalidArgumentException::class);

    expect(fn () => codec()->encodeFunction([fnAbi('f', ['uint256'])], 'f', [1, 2]))
        ->toThrow(InvalidArgumentException::class);
});

// --- address and bytesN ------------------------------------------------------

it('rejects an address of the wrong length rather than padding it into another one', function () {
    expect(fn () => codec()->encodeFunction([fnAbi('f', ['address'])], 'f', ['0xabc']))
        ->toThrow(InvalidArgumentException::class);

    // Over-long input used to shift every following word
    expect(fn () => codec()->encodeFunction([fnAbi('f', ['address', 'uint256'])], 'f', [ADDR.str_repeat('f', 24), 1]))
        ->toThrow(InvalidArgumentException::class);
});

it('keeps the calldata word aligned for a valid address', function () {
    $data = codec()->encodeFunction([fnAbi('f', ['address', 'uint256'])], 'f', [ADDR, 1]);

    expect(strlen($data) - 10)->toBe(128);
});

it('checks the width of bytesN', function () {
    expect(codec()->encodeFunction([fnAbi('f', ['bytes32'])], 'f', ['0x'.str_repeat('ab', 32)]))
        ->toEndWith(str_repeat('ab', 32));

    expect(fn () => codec()->encodeFunction([fnAbi('f', ['bytes32'])], 'f', ['0xab']))
        ->toThrow(InvalidArgumentException::class);

    expect(codec()->encodeFunction([fnAbi('f', ['bytes4'])], 'f', ['0xdeadbeef']))
        ->toEndWith('deadbeef'.str_repeat('0', 56));
});

it('rejects bytes with an odd number of hex characters', function () {
    expect(fn () => codec()->encodeFunction([fnAbi('f', ['bytes'])], 'f', ['0xabc']))
        ->toThrow(InvalidArgumentException::class);
});

// --- signed integers ---------------------------------------------------------

it('encodes signed integers in two complement', function () {
    expect(codec()->encodeFunction([fnAbi('f', ['int256'])], 'f', [-1]))
        ->toEndWith(str_repeat('f', 64));

    expect(codec()->encodeFunction([fnAbi('f', ['int256'])], 'f', ['-5']))
        ->toEndWith(str_repeat('f', 63).'b');

    expect(codec()->encodeFunction([fnAbi('f', ['int256'])], 'f', [123]))
        ->toEndWith(str_pad('7b', 64, '0', STR_PAD_LEFT));
});

it('rejects a signed value outside the declared width', function () {
    expect(fn () => codec()->encodeFunction([fnAbi('f', ['int8'])], 'f', [128]))
        ->toThrow(InvalidArgumentException::class);

    expect(fn () => codec()->encodeFunction([fnAbi('f', ['int8'])], 'f', [-129]))
        ->toThrow(InvalidArgumentException::class);
});

// --- bool --------------------------------------------------------------------

it('rejects a non boolean for a bool parameter', function () {
    expect(codec()->encodeFunction([fnAbi('f', ['bool'])], 'f', [true]))
        ->toEndWith(str_pad('1', 64, '0', STR_PAD_LEFT));

    expect(fn () => codec()->encodeFunction([fnAbi('f', ['bool'])], 'f', ['yes']))
        ->toThrow(InvalidArgumentException::class);
});

// --- overloads ---------------------------------------------------------------

it('picks the overload matching the argument count', function () {
    $abi = [
        fnAbi('safeTransferFrom', ['address', 'address', 'uint256']),
        fnAbi('safeTransferFrom', ['address', 'address', 'uint256', 'bytes']),
    ];

    $three = codec()->encodeFunction($abi, 'safeTransferFrom', [ADDR, ADDR, 1]);
    $four = codec()->encodeFunction($abi, 'safeTransferFrom', [ADDR, ADDR, 1, '0xdead']);

    expect(substr($three, 0, 10))->toBe('0x42842e0e');
    expect(substr($four, 0, 10))->toBe('0xb88d4fde');
});

it('accepts a full signature to choose an overload', function () {
    $abi = [
        fnAbi('safeTransferFrom', ['address', 'address', 'uint256']),
        fnAbi('safeTransferFrom', ['address', 'address', 'uint256', 'bytes']),
    ];

    expect(codec()->encodeFunction($abi, 'safeTransferFrom(address,address,uint256)', [ADDR, ADDR, 1]))
        ->toStartWith('0x42842e0e');
});

it('refuses to guess when two overloads share an argument count', function () {
    $abi = [fnAbi('f', ['uint256']), fnAbi('f', ['address'])];

    expect(fn () => codec()->encodeFunction($abi, 'f', [1]))
        ->toThrow(RuntimeException::class);
});

it('reports an unknown signature clearly', function () {
    expect(fn () => codec()->encodeFunction([fnAbi('f', ['uint256'])], 'f(address)', [ADDR]))
        ->toThrow(RuntimeException::class);
});

// --- dynamic offsets ---------------------------------------------------------

it('computes offsets for two dynamic parameters', function () {
    $data = substr(codec()->encodeFunction([fnAbi('f', ['string', 'string'])], 'f', ['ab', 'cd']), 10);

    $words = str_split($data, 64);

    // head: two offsets; tails follow at 0x40 and 0x80
    expect(hexdec($words[0]))->toBe(64);
    expect(hexdec($words[1]))->toBe(128);
    expect(hexdec($words[2]))->toBe(2);
    expect($words[3])->toStartWith(bin2hex('ab'));
    expect(hexdec($words[4]))->toBe(2);
    expect($words[5])->toStartWith(bin2hex('cd'));
});

it('pads a dynamic value that is not a multiple of 32 bytes', function () {
    $data = substr(codec()->encodeFunction([fnAbi('f', ['string'])], 'f', [str_repeat('x', 33)]), 10);

    // offset + length + two payload words
    expect(strlen($data))->toBe(4 * 64);
});
