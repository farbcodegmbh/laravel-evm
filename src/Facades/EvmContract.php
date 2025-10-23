<?php

namespace Farbcode\LaravelEvm\Facades;

use Farbcode\LaravelEvm\Contracts\ContractClient;
use Illuminate\Support\Facades\Facade;

class EvmContract extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return ContractClient::class;
    }
}
