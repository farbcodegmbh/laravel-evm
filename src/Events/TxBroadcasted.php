<?php

// src/Events/TxBroadcasted.php

namespace Farbcode\LaravelEvm\Events;

class TxBroadcasted
{
    public function __construct(public string $txHash, public array $fields) {}
}
