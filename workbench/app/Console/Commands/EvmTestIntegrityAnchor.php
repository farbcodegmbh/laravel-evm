<?php

namespace Workbench\App\Console\Commands;

use Farbcode\LaravelEvm\Facades\Evm;
use Farbcode\LaravelEvm\Facades\EvmLogs;
use Farbcode\LaravelEvm\Support\Encoding;
use Farbcode\LaravelEvm\Support\LogFilterBuilder;
use Illuminate\Console\Command;

class EvmTestIntegrityAnchor extends Command
{
    protected $signature = 'evmtest:integrity';

    protected $description = 'Simple Smart Contract interaction test';

    public function handle(): int
    {
        $abiPath = storage_path('app/abi/IntegrityAnchor.abi.json');
        if (! file_exists($abiPath)) {
            $this->error('ABI file missing: '.$abiPath);

            return self::FAILURE;
        }
        $abi = file_get_contents($abiPath);
        $contract = Evm::at('0x4B3cae3a09B8441287D6ae6593b298eE95686a8D', $abi);

        $this->info('owner:  '.$contract->call('owner')->as('address'));
        $this->info('writer: '.$contract->call('writer')->as('address'));
        $this->info('isAnchored: '.$contract->call('isAnchored', [11, '0x60ead0230a911bf0005e7b2f1a001e5181e942ed'])->raw());
        $this->info('seenByElection: '.$contract->call('seenByElection', [10, '0x60ead0230a911bf0005e7b2f1a001e5181e942ed'])->raw());

        $jobId = $contract->sendAsync('anchor', [10, Encoding::stringToBytes32('hash2'), Encoding::stringToBytes32('meta')]);
        $this->info('jobId: '.$jobId);

        $logs = EvmLogs::query()
            ->fromBlock(28_000_000)
            ->eventByAbi($abi, 'Anchored')
            ->get();

        $this->info('Logs: '.print_r($logs, true));

        $decoded = array_map(fn ($l) => LogFilterBuilder::decodeEvent($abi, $l), $logs);

        $this->table(array_keys($decoded[0]), array_map(fn ($row) => [
            'electionId' => $row['electionId'],
            'hashValue' => Encoding::bytes32ToString($row['hashValue']),
            'author' => $row['author'],
            'meta' => Encoding::bytes32ToString($row['meta']),
        ], $decoded));

        return self::SUCCESS;
    }
}
