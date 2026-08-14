<?php

// src/Contracts/NonceManager.php

namespace Farbcode\LaravelEvm\Contracts;

interface NonceManager
{
    public function getPendingNonce(string $address, callable $fetcher): int;

    public function markUsed(string $address, int $nonce): void;

    /**
     * Drop what is remembered for this address so the next call re-reads the
     * nonce from the chain.
     *
     * Any cached nonce is a bet that nothing else moved the account on. A
     * failed broadcast, a dropped transaction or a send from a second system
     * breaks that bet, and without a way to forget, the cached value stays
     * wrong for the life of the process.
     */
    public function invalidate(string $address): void;
}
