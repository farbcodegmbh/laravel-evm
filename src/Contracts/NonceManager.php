<?php
// src/Contracts/NonceManager.php
namespace Farbcode\LaravelEvm\Contracts;

interface NonceManager
{
    public function getPendingNonce(string $address, callable $fetcher): int;
    public function markUsed(string $address, int $nonce): void;
}
