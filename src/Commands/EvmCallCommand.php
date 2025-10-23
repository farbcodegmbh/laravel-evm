<?php

// src/Commands/EvmCallCommand.php

namespace Farbcode\LaravelEvm\Commands;

use Farbcode\LaravelEvm\Facades\LaravelEvm as LaravelEvmFacade;
use Illuminate\Console\Command;

// Facade Alias

class EvmCallCommand extends Command
{
    protected $signature = 'evm:call {address} {abiPath} {function} {args*}';

    protected $description = 'Static eth_call on a contract function';

    public function handle(): int
    {
        $addr = $this->argument('address');
        $abi = file_get_contents($this->argument('abiPath'));
        $fn = $this->argument('function');
        $args = $this->argument('args');

        $res = LaravelEvmFacade::at($addr, $abi)->call($fn, $args);
        $this->line(json_encode($res, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return self::SUCCESS;
    }
}
