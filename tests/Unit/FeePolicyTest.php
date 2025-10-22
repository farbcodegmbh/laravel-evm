<?php

use Farbcode\LaravelEvm\Support\SimpleFeePolicy;

it('suggests and replaces fees with increasing values', function () {
    $policy = new SimpleFeePolicy([
        'min_priority_gwei' => 3,
        'min_maxfee_gwei' => 40,
        'base_multiplier' => 3,
        'replacement_factor' => 1.5,
    ]);

    // base gas price ~ 100 gwei
    [$prio, $max] = $policy->suggest(fn() => '0x'.dechex(100 * 1_000_000_000));
    expect($prio)->toBeGreaterThan(0)->and($max)->toBeGreaterThan($prio);

    [$prio2, $max2] = $policy->replace($prio, $max);
    expect($prio2)->toBeGreaterThan($prio)->and($max2)->toBeGreaterThan($max);
});
