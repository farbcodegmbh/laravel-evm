<?php

namespace Farbcode\LaravelEvm\Support;

use Farbcode\LaravelEvm\Contracts\TransactionStore;
use Farbcode\LaravelEvm\Models\EvmTransaction;
use Illuminate\Support\Facades\Log;
use Throwable;

class EloquentTransactionStore implements TransactionStore
{
    public function record(string $requestId, array $attributes): void
    {
        try {
            EvmTransaction::updateOrCreate(['request_id' => $requestId], $attributes);
        } catch (Throwable $e) {
            // Bookkeeping must never take down a transaction that is already
            // on its way. A missing migration is the likeliest cause.
            Log::warning('laravel-evm: could not record transaction', [
                'request_id' => $requestId,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
