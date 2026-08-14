<?php

// src/Contracts/FeePolicy.php

namespace Farbcode\LaravelEvm\Contracts;

use Farbcode\LaravelEvm\Support\FeeSnapshot;

interface FeePolicy
{
    /**
     * Initial fee caps for a transaction.
     *
     * @return array{0: int, 1: int} [maxPriorityFeePerGas, maxFeePerGas] in wei.
     *                               maxFeePerGas must be at least the priority
     *                               fee, or the node rejects the transaction.
     */
    public function suggest(FeeSnapshot $snapshot): array;

    /**
     * Raised caps for replacing a transaction that is still pending.
     *
     * Both caps have to rise by at least 10 percent for geth and erigon to
     * accept the replacement.
     *
     * @return array{0: int, 1: int} [maxPriorityFeePerGas, maxFeePerGas] in wei
     */
    public function replace(int $oldPriority, int $oldMax): array;
}
