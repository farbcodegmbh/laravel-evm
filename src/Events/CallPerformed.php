<?php

namespace Farbcode\LaravelEvm\Events;

class CallPerformed
{
    public function __construct(
        public string $from,
        public string $address,
        public string $function,
        public array $args,
        public mixed $rawResult
    ) {}
}

