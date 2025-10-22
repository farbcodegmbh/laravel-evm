<?php

use Farbcode\LaravelEvm\Crypto\LocalNonceManager;

it('increments nonce locally without refetching until markUsed', function () {
    $mgr = new LocalNonceManager;
    $fetchCount = 0;
    $fetcher = function () use (&$fetchCount) {
        $fetchCount++;

        return 7; // starting nonce
    };

    $n1 = $mgr->getPendingNonce('0xABC', $fetcher);
    $n2 = $mgr->getPendingNonce('0xABC', $fetcher);
    expect($n1)->toBe(7)->and($n2)->toBe(7)->and($fetchCount)->toBe(1);

    $mgr->markUsed('0xABC', 7);
    $n3 = $mgr->getPendingNonce('0xABC', $fetcher);
    expect($n3)->toBe(8)->and($fetchCount)->toBe(1);
});
