<?php

namespace Farbcode\LaravelEvm;

use Illuminate\Support\Facades\Facade;

class LaravelEvm extends Facade {

    protected static function getFacadeAccessor()
    {
        return Contracts\ContractClient::class;
    }
}
