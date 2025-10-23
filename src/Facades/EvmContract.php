<?php

namespace Farbcode\LaravelEvm\Facades;

use Illuminate\Support\Facades\Facade;
use Farbcode\LaravelEvm\Contracts\ContractClient;

class EvmContract extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return ContractClient::class;
    }
}
