<?php

namespace Farbcode\LaravelEvm\Support;

use Farbcode\LaravelEvm\Contracts\TransactionStore;

/**
 * Default store: keeps nothing, so the package needs no database.
 */
class NullTransactionStore implements TransactionStore
{
    public function record(string $requestId, array $attributes): void {}
}
