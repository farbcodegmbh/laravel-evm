<?php

// src/Commands/EvmHealthCommand.php

namespace Farbcode\LaravelEvm\Commands;

use Farbcode\LaravelEvm\Contracts\RpcClient;
use Illuminate\Console\Command;

class EvmHealthCommand extends Command
{
    protected $signature = 'evm:health';

    protected $description = 'Show chain id and latest block to verify RPC health';

    public function handle(RpcClient $rpc): int
    {
        $h = $rpc->health();
        $this->info('Chain ID '.$h['chainId']);
        $this->info('Latest block '.$h['block']);

        return self::SUCCESS;
    }
}
