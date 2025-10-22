<?php
// src/Commands/EvmWaitCommand.php
namespace Farbcode\LaravelEvm\Commands;

use Illuminate\Console\Command;
use Farbcode\LaravelEvm\Contracts\RpcClient;

class EvmWaitCommand extends Command
{
    protected $signature = 'evm:wait {txHash} {--timeout=120} {--poll=800}';
    protected $description = 'Wait for a transaction receipt';

    public function handle(RpcClient $rpc): int
    {
        $tx = $this->argument('txHash');
        $timeout = (int) $this->option('timeout');
        $poll = (int) $this->option('poll');

        $deadline = time() + $timeout;
        while (time() < $deadline) {
            $rec = $rpc->call('eth_getTransactionReceipt', [$tx]);
            if (!empty($rec)) {
                $this->line(json_encode($rec, JSON_PRETTY_PRINT));
                return self::SUCCESS;
            }
            usleep($poll * 1000);
        }

        $this->error('No receipt within timeout');
        return self::FAILURE;
    }
}
