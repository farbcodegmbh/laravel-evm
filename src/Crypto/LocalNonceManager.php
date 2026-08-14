<?php

// src/Crypto/LocalNonceManager.php

namespace Farbcode\LaravelEvm\Crypto;

use Farbcode\LaravelEvm\Contracts\NonceManager;

/**
 * Remembers the next nonce per address for the life of the process, so a run of
 * transactions from one worker does not have to ask the chain every time.
 *
 * This only holds while a single worker owns the signing address. Anything that
 * moves the account behind its back invalidates the cache, which is why callers
 * must call invalidate() when a send fails or a transaction is dropped.
 */
class LocalNonceManager implements NonceManager
{
    private array $cache = [];

    public function getPendingNonce(string $address, callable $fetcher): int
    {
        $addr = strtolower($address);
        if (! array_key_exists($addr, $this->cache)) {
            $this->cache[$addr] = (int) $fetcher();
        }

        return $this->cache[$addr];
    }

    public function markUsed(string $address, int $nonce): void
    {
        $this->cache[strtolower($address)] = $nonce + 1;
    }

    public function invalidate(string $address): void
    {
        unset($this->cache[strtolower($address)]);
    }
}
