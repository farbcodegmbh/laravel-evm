<?php

// src/Commands/EvmSendCommand.php

namespace Farbcode\LaravelEvm\Commands;

use Farbcode\LaravelEvm\Facades\LaravelEvm as LaravelEvmFacade;
use Illuminate\Console\Command;

// Facade Alias

class EvmSendCommand extends Command
{
    protected $signature = 'evm:send {address} {abiPath} {function} {args*} {--timeout=120}';

    protected $description = 'Queue a non blocking transaction for a contract function';

    public function handle(): int
    {
        $addr = $this->argument('address');
        $abi = file_get_contents($this->argument('abiPath'));
        $fn = $this->argument('function');
        $args = $this->argument('args');
        $timeout = (int) $this->option('timeout');

    $jobId = LaravelEvmFacade::at($addr, $abi)->sendAsync($fn, $args, ['timeout' => $timeout]);
        $this->info('Queued job id '.$jobId);
        $this->info('Queue '.config('evm.tx.queue'));

        return self::SUCCESS;
    }
}
