<?php

namespace Farbcode\LaravelEvm\Facades;

use Farbcode\LaravelEvm\Contracts\ContractClient;
use Illuminate\Support\Facades\Facade;

/**
 * Provides access to the ContractClient.
 *
 * @method static ContractClient at(string $address, array|string $abi = [])
 * @method static mixed call(string $function, array $args = [])
 * @method static string sendAsync(string $function, array $args = [], array $opts = [])
 * @method static ?array wait(string $txHash, int $timeoutSec = 120, int $pollMs = 800)
 * @method static int estimateGas(string $data, ?string $from = null)
 */
class Evm extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return ContractClient::class;
    }
}
