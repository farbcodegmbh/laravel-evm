<?php

// src/Commands/EvmGenerateAddressCommand.php

namespace Farbcode\LaravelEvm\Commands;

use Farbcode\LaravelEvm\Support\Encoding;
use Illuminate\Console\Command;
use kornrunner\Ethereum\Address as EthAddress;

class EvmGenerateAddressCommand extends Command
{
    protected $signature = 'evm:address:generate {--count=1 : Number of addresses to generate} {--json : Output JSON array instead of table}';

    protected $description = 'Generate one or more fresh Ethereum addresses (private key + public key + checksum address)';

    public function handle(): int
    {
        $count = (int) $this->option('count');
        if ($count < 1 || $count > 50) {
            $this->error('Count must be between 1 and 50');

            return self::FAILURE;
        }

        $rows = [];
        for ($i = 0; $i < $count; $i++) {
            // Library auto-generates secure private key if none supplied.
            // Wrap in try/catch to handle rare ECC overflow edge cases on certain PHP builds.
            try {
                $addr = new EthAddress;
            } catch (\Throwable $e) {
                $this->error('Failed to generate address: '.$e->getMessage());

                return self::FAILURE;
            }
            $privateKey = $addr->getPrivateKey(); // 64 hex (no 0x)
            $publicKey = $addr->getPublicKey();   // 64 byte uncompressed key, no 0x and no 04 prefix
            $rawAddress = $addr->get();           // 40 hex lowercase
            $checksum = $this->toChecksum('0x'.$rawAddress);

            $rows[] = [
                'address' => $checksum,
                'private_key' => '0x'.$privateKey,
                'public_key' => '0x'.$publicKey,
            ];
        }

        if ($this->option('json')) {
            $this->line(json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            $this->table(['Address', 'Private Key', 'Public Key'], array_map(fn ($r) => [$r['address'], $r['private_key'], substr($r['public_key'], 0, 20).'…'], $rows));
            $this->info('IMPORTANT: Store private keys securely. They will NOT be shown again.');
        }

        return self::SUCCESS;
    }

    private function toChecksum(string $address): string
    {
        return Encoding::toChecksumAddress($address);
    }
}
