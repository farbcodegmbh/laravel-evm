<?php

namespace Farbcode\LaravelEvm\Contracts;

interface ContractClient
{
    public function at(string $address, array|string $abi = []): self;

    /** Synchronous read only call returning raw hex or decoded value depending on ABI usage. */
    public function call(string $function, array $args = []): mixed;

    /** Enqueue a non blocking write job. Returns job id string. */
    public function sendAsync(string $function, array $args = [], array $opts = []): string;

    /** Wait for a receipt with timeout returns receipt array or null. */
    public function wait(string $txHash, int $timeoutSec = 120, int $pollMs = 800): ?array;

    /** Encode data and estimate gas with padding. */
    public function estimateGas(string $data, ?string $from = null): int;
}
