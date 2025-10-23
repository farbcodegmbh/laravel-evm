<?php

namespace Farbcode\LaravelEvm\Facades;

use Illuminate\Support\Facades\Facade;
use Farbcode\LaravelEvm\Contracts\ContractClient;

/**
 * Primary convenience facade matching legacy alias LaravelEvm.
 * Provides access to the ContractClient.
 *
 * @method static \Farbcode\LaravelEvm\Contracts\ContractClient at(string $address, array|string $abi = [])
 * @method static mixed call(string $function, array $args = [])
 * @method static string sendAsync(string $function, array $args = [], array $opts = [])
 * @method static ?array wait(string $txHash, int $timeoutSec = 120, int $pollMs = 800)
 * @method static int estimateGas(string $data, ?string $from = null)
 */
class LaravelEvm extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return ContractClient::class;
    }
}
