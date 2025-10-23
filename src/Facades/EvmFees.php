<?php

namespace Farbcode\LaravelEvm\Facades;

use Illuminate\Support\Facades\Facade;
use Farbcode\LaravelEvm\Contracts\FeePolicy;

class EvmFees extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return FeePolicy::class;
    }
}
