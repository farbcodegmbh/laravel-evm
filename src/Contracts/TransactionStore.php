<?php

namespace Farbcode\LaravelEvm\Contracts;

/**
 * Persists the lifecycle of an outgoing transaction.
 *
 * Optional by design: the package works without a database, so the default
 * binding discards everything. Enable evm.tracking.enabled to keep a record.
 */
interface TransactionStore
{
    /**
     * Create or update the record for a queued transaction.
     *
     * @param  string  $requestId  the id sendAsync() returned to the caller
     * @param  array<string, mixed>  $attributes
     */
    public function record(string $requestId, array $attributes): void;
}
