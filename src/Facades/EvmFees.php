<?php

namespace Farbcode\LaravelEvm\Facades;

use Farbcode\LaravelEvm\Contracts\FeePolicy;
use Illuminate\Support\Facades\Facade;

class EvmFees extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return FeePolicy::class;
    }
}
