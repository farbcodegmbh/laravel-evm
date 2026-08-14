<?php

namespace Farbcode\LaravelEvm\Events;

/**
 * The transaction was mined but the contract call reverted. Gas was spent and
 * no state change took place.
 */
class TxReverted
{
    public function __construct(public string $txHash, public array $receipt, public mixed $payload = null) {}
}
