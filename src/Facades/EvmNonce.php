<?php

namespace Farbcode\LaravelEvm\Facades;

use Farbcode\LaravelEvm\Contracts\NonceManager;
use Illuminate\Support\Facades\Facade;

class EvmNonce extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return NonceManager::class;
    }
}
