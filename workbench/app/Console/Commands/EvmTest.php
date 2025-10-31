<?php

namespace Workbench\App\Console\Commands;

use Farbcode\LaravelEvm\Facades\Evm;
use Farbcode\LaravelEvm\Facades\EvmRpc;
use Illuminate\Console\Command;

class EvmTest extends Command
{
    protected $signature = 'evmtest:test';

    protected $description = 'Simple Smart Contract interaction test';

    public function handle(): int
    {
        $abiPath = storage_path('app/abi/HelloWorld.abi.json');
        if (! file_exists($abiPath)) {
            $this->error('ABI file missing: '.$abiPath);

            return self::FAILURE;
        }
        $abi = file_get_contents($abiPath);
        $contract = Evm::at('0x370E67feF90F06fD3fAB7B7B41d9BFAEd01329A9', $abi);

        $this->info('Health: '.print_r(EvmRpc::health(), true));

        $result = $contract->call('message');
        $this->info('String: '.$result->as('string'));

        $rand = random_int(1, 1000);
        $jobId = $contract->sendAsync('update', ['Hello from Laravel EVM! '.$rand]);
        $this->info('jobId: '.$jobId.', random number: '.$rand);

        return self::SUCCESS;
    }
}
