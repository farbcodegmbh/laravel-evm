<?php

namespace Farbcode\LaravelEvm\Contracts;

interface ContractClient
{
    /**
     * Returns a new handle bound to the given contract and ABI. Implementations
     * must not mutate the instance this is called on, so that handles taken
     * earlier keep pointing at their own contract.
     */
    public function at(string $address, array|string $abi = []): self;

    /** Synchronous read only call returning raw hex or decoded value depending on ABI usage. */
    public function call(string $function, array $args = []): mixed;

    /**
     * Enqueue a non blocking write job. Returns job id string.
     *
     * $opts accepts:
     *   value             wei to send with a payable call, as an int, a decimal
     *                     string or 0x-hex. Validated when the job is queued.
     *   timeout           seconds to wait for a receipt before replacing
     *   poll_ms           receipt poll interval
     *   max_replacements  fee bump attempts
     *
     * Optional $payload allows attaching any serializable context (e.g. an Eloquent model) that will
     * be forwarded to all transaction lifecycle events (TxQueued, TxBroadcasted, TxReplaced, TxMined, TxFailed).
     */
    public function sendAsync(string $function, array $args = [], array $opts = [], mixed $payload = null): string;

    /** Wait for a receipt with timeout returns receipt array or null. */
    public function wait(string $txHash, int $timeoutSec = 120, int $pollMs = 800): ?array;

    /** Encode data and estimate gas with padding. */
    public function estimateGas(string $data, ?string $from = null): int;
}
