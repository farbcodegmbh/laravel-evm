<?php
// src/Events/TxFailed.php
namespace Farbcode\LaravelEvm\Events;

class TxFailed
{
    public function __construct(public string $to, public string $data, public string $reason) {}
}
