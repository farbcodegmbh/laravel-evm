<?php

// src/Events/TxMined.php

namespace Farbcode\LaravelEvm\Events;

class TxMined
{
    public function __construct(public string $txHash, public array $receipt) {}
}
