<?php

use Farbcode\LaravelEvm\Support\CallResult;

function word(string $hex): string
{
    return '0x'.str_pad($hex, 64, '0', STR_PAD_LEFT);
}

it('decodes uint256 values beyond PHP_INT_MAX without losing precision', function () {
    expect(new CallResult(word('8ac7230489e80000'))->as('uint256'))
        ->toBe('10000000000000000000');

    expect(new CallResult(word('1bc16d674ec80000'))->as('uint256'))
        ->toBe('2000000000000000000');

    expect(new CallResult('0x'.str_repeat('f', 64))->as('uint256'))
        ->toBe('115792089237316195423570985008687907853269984665640564039457584007913129639935');
});

it('returns uint values as decimal strings regardless of magnitude', function () {
    expect(new CallResult(word('0'))->as('uint256'))->toBe('0');
    expect(new CallResult(word('1'))->as('uint'))->toBe('1');
    expect(new CallResult(word('de0b6b3a7640000'))->as('uint256'))->toBe('1000000000000000000');
});

it('resolves two complement for signed integers', function () {
    expect(new CallResult('0x'.str_repeat('f', 64))->as('int256'))->toBe('-1');

    expect(new CallResult('0x'.str_repeat('f', 63).'b')->as('int256'))->toBe('-5');

    expect(new CallResult(word('7b'))->as('int256'))->toBe('123');
});

it('honours the declared width for narrow integer types', function () {
    // 0xff is -1 as int8 but 255 as uint8
    expect(new CallResult(word('ff'))->as('int8'))->toBe('-1');
    expect(new CallResult(word('ff'))->as('uint8'))->toBe('255');
});

it('rejects malformed integer types', function () {
    expect(fn () => new CallResult(word('1'))->as('uint7'))
        ->toThrow(InvalidArgumentException::class);

    expect(fn () => new CallResult(word('1'))->as('uintfoo'))
        ->toThrow(InvalidArgumentException::class);
});

it('decodes bool from the whole word rather than the last nibble', function () {
    expect(new CallResult(word('1'))->as('bool'))->toBeTrue();
    expect(new CallResult(word('0'))->as('bool'))->toBeFalse();
    // A non-canonical truthy value must still read as true
    expect(new CallResult(word('3'))->as('bool'))->toBeTrue();
});

it('decodes addresses and keeps the raw hex available', function () {
    $result = new CallResult(word('5aaeb6053f3e94c9b9a09f33669435e7ef1beaed'));

    expect($result->as('address'))->toBe('0x5aaeb6053f3e94c9b9a09f33669435e7ef1beaed');
    expect($result->raw())->toBe(word('5aaeb6053f3e94c9b9a09f33669435e7ef1beaed'));
    expect((string) $result)->toBe($result->raw());
});
