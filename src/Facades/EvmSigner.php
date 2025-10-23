<?php

namespace Farbcode\LaravelEvm\Facades;

use Farbcode\LaravelEvm\Contracts\Signer;
use Illuminate\Support\Facades\Facade;

class EvmSigner extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return Signer::class;
    }
}
