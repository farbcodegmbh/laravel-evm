<?php

namespace Workbench\App\Console\Commands;

use Farbcode\LaravelEvm\Facades\LaravelEvm;
use Farbcode\LaravelEvm\Facades\EvmLogs;
use Farbcode\LaravelEvm\Support\LogFilterBuilder;
use Illuminate\Console\Command;

class EvmCallCommand extends Command
{
    protected $signature = 'evmtest:test';

    protected $description = 'Simple Smart Contract interaction test';

    public function handle(): int
    {
        $abiPath = storage_path('app/abi/HelloWorld.abi.json');
        if (!file_exists($abiPath)) {
            $this->error('ABI file missing: ' . $abiPath);
            return self::FAILURE;
        }
        $abi = file_get_contents($abiPath);
        $contract = LaravelEvm::at('0x370E67feF90F06fD3fAB7B7B41d9BFAEd01329A9', $abi);

        $result = $contract->call('message');
        $this->info('Raw: ' . $result->raw());
        $this->info('String: ' . $result->as('string'));

        $jobId = $contract->sendAsync("update", ['Hello from Laravel EVM!']);
        $this->info('jobId: ' . $jobId);

        /*        $logs = EvmLogs::query()
            ->fromBlock(18_000_000)
            ->eventByAbi($abi, 'Transfer')
            ->get();

        dump($logs);
        */

        //$decoded = array_map(fn($l) => LogFilterBuilder::decodeEvent($abi, $l), $logs);
        //dump($decoded);

        return 1;
    }
}
