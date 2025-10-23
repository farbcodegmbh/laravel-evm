<?php

namespace Farbcode\LaravelEvm\Facades;

use Illuminate\Support\Facades\Facade;
use Farbcode\LaravelEvm\Contracts\Signer;

class EvmSigner extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return Signer::class;
    }
}
