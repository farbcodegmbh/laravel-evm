<?php

// src/Crypto/LocalNonceManager.php

namespace Farbcode\LaravelEvm\Crypto;

use Farbcode\LaravelEvm\Contracts\NonceManager;

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
}
