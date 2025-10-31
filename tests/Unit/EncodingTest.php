<?php

use Farbcode\LaravelEvm\Support\Encoding;

it('encodes and decodes bytes32 correctly', function () {
    $hex = Encoding::stringToBytes32('test22');
    expect($hex)->toStartWith('0x')->and(strlen($hex))->toBe(66);
    $str = Encoding::bytes32ToString($hex);
    expect($str)->toBe('test22');
});

it('truncates long strings by default', function () {
    $long = str_repeat('A', 40);
    $hex = Encoding::stringToBytes32($long); // default truncate
    $decoded = Encoding::bytes32ToString($hex);
    expect(strlen($decoded))->toBe(32);
});

it('throws when truncate disabled and too long', function () {
    $long = str_repeat('B', 40);
    expect(fn () => Encoding::stringToBytes32($long, truncate: false))->toThrow(InvalidArgumentException::class);
});
