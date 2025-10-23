<?php

namespace Farbcode\LaravelEvm\Facades;

use Illuminate\Support\Facades\Facade;
use Farbcode\LaravelEvm\Contracts\NonceManager;

class EvmNonce extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return NonceManager::class;
    }
}
