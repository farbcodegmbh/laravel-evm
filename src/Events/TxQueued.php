<?php
// src/Events/TxQueued.php
namespace Farbcode\LaravelEvm\Events;

class TxQueued
{
    public function __construct(public string $to, public string $data) {}
}
