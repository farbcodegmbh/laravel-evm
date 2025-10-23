<?php

namespace Farbcode\LaravelEvm\Facades;

use Farbcode\LaravelEvm\Contracts\RpcClient;
use Illuminate\Support\Facades\Facade;

class EvmRpc extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return RpcClient::class;
    }
}
