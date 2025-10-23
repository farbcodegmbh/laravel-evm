<?php

namespace Farbcode\LaravelEvm\Facades;

use Illuminate\Support\Facades\Facade;
use Farbcode\LaravelEvm\Contracts\RpcClient;

class EvmRpc extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return RpcClient::class;
    }
}
