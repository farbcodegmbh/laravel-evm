<?php

// src/Contracts/FeePolicy.php

namespace Farbcode\LaravelEvm\Contracts;

interface FeePolicy
{
    public function suggest(callable $gasPriceFetcher): array; // [priorityWei, maxFeeWei]

    public function replace(int $oldPriority, int $oldMax): array;
}
