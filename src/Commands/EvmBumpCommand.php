<?php

namespace Farbcode\LaravelEvm\Commands;

use Farbcode\LaravelEvm\Contracts\FeePolicy;
use Farbcode\LaravelEvm\Contracts\RpcClient;
use Farbcode\LaravelEvm\Contracts\Signer;
use Illuminate\Console\Command;
use Web3p\EthereumTx\EIP1559Transaction;

class EvmBumpCommand extends Command
{
    protected $signature = 'evm:bump {--nonce=} {--original=} {--factor=2.0} {--gas=21000} {--priority=} {--max=} {--dry-run} {--auto}';

    protected $description = 'Send a high-fee empty self transaction to replace a stuck pending transaction (same nonce).';

    public function handle(RpcClient $rpc, Signer $signer, FeePolicy $fees): int
    {
        $address = $signer->getAddress();
        $chainId = (int) config('evm.chain_id');

        // Fetch latest and pending nonce
        $pendingHex = $rpc->call('eth_getTransactionCount', [$address, 'pending']);
        $latestHex = $rpc->call('eth_getTransactionCount', [$address, 'latest']);
        $pending = hexdec($pendingHex);
        $latest = hexdec($latestHex);

        $specifiedNonceOpt = $this->option('nonce');
        $nonce = null;
        if ($specifiedNonceOpt !== null) {
            $nonce = (int) $specifiedNonceOpt;
        } else {
            if ($pending > $latest) {
                $nonce = $pending - 1; // last pending
            } else {
                $this->error('No pending transactions detected (pending == latest). Use --nonce to force replacement.');

                return self::FAILURE;
            }
        }
        if ($nonce < 0) {
            $this->error('Calculated nonce is negative; abort.');

            return self::FAILURE;
        }

        // Optional original tx fetch
        $origHash = $this->option('original');
        $origPriority = null;
        $origMaxFee = null;
        $origGas = null;
        if ($origHash) {
            try {
                $orig = $rpc->call('eth_getTransactionByHash', [$origHash]);
                if (is_array($orig) && isset($orig['nonce'])) {
                    $origNonce = hexdec($orig['nonce']);
                    if ($origNonce !== $nonce) {
                        $this->warn('Original tx nonce '.$origNonce.' != target nonce '.$nonce.'; continuing but replacement may fail.');
                    }
                    // Legacy or EIP-1559: fields differ; try both
                    if (isset($orig['maxPriorityFeePerGas'])) {
                        $origPriority = hexdec($orig['maxPriorityFeePerGas']);
                    } elseif (isset($orig['gasPrice'])) {
                        $origPriority = hexdec($orig['gasPrice']); // treat as priority baseline
                    }
                    if (isset($orig['maxFeePerGas'])) {
                        $origMaxFee = hexdec($orig['maxFeePerGas']);
                    } elseif (isset($orig['gasPrice'])) {
                        $origMaxFee = hexdec($orig['gasPrice']);
                    }
                    if (isset($orig['gas'])) {
                        $origGas = hexdec($orig['gas']);
                    }
                } else {
                    $this->warn('Could not fetch original tx or unexpected response shape.');
                }
            } catch (\Throwable $e) {
                $this->warn('Fetch original tx failed: '.$e->getMessage());
            }
        }

        // Base gas price
        $gasPriceHex = $rpc->call('eth_gasPrice');
        $base = is_string($gasPriceHex) ? hexdec($gasPriceHex) : (int) $gasPriceHex;

        $factor = (float) $this->option('factor');
        $userPrio = $this->option('priority');
        $userMax = $this->option('max');
        [$suggestPrio, $suggestMax] = $fees->suggest(fn () => $gasPriceHex);

        // Auto mode: derive min bump from original tx if provided
        $priority = $userPrio !== null ? (int) $userPrio : max($suggestPrio, (int) ($base * 0.3));
        $maxFee = $userMax !== null ? (int) $userMax : max($suggestMax, (int) ($base * $factor));

        if ($this->option('auto') && $origPriority !== null && $origMaxFee !== null) {
            $minPriority = max((int) ($origPriority * 1.125), $origPriority + 1_000_000_000); // +12.5% or +1 gwei
            $minMaxFee = max((int) ($origMaxFee * 1.125), $origMaxFee + 2_000_000_000); // +12.5% or +2 gwei
            $priority = max($priority, $minPriority);
            $maxFee = max($maxFee, $minMaxFee);
        }

        if ($maxFee <= $priority) {
            $maxFee = $priority * 2;
        }

        $gasLimit = (int) $this->option('gas');
        if ($gasLimit < 21000) {
            $gasLimit = 21000;
        }
        if ($origGas !== null) {
            // Keep at least original gas limit if higher (avoid underestimating)
            $gasLimit = max($gasLimit, $origGas);
        }

        // ChainId sanity check
        $rpcChainHex = $rpc->call('eth_chainId');
        $rpcChain = is_string($rpcChainHex) ? hexdec($rpcChainHex) : (int) $rpcChainHex;
        if ($rpcChain !== $chainId && ! $this->option('dry-run')) {
            $this->error('ChainId mismatch: local='.$chainId.' remote='.$rpcChain.' (abort)');

            return self::FAILURE;
        }

        $fields = [
            'chainId' => $chainId,
            'nonce' => $nonce,
            'maxPriorityFeePerGas' => $priority,
            'maxFeePerGas' => $maxFee,
            'gas' => $gasLimit,
            'to' => $address,
            'value' => 0,
            'data' => '0x',
            'accessList' => [],
        ];

        $this->line('Prepared replacement transaction:');
        $rows = [[
            $nonce,
            $priority,
            $maxFee,
            $gasLimit,
        ]];
        $this->table(['nonce', 'priority', 'maxFee', 'gasLimit'], $rows);
        if ($origPriority !== null) {
            $this->info('Original priority: '.$origPriority.' original maxFee: '.$origMaxFee.' original gas: '.$origGas);
        }
        $this->info('Base fee (approx from gasPrice): '.$base);

        if ($this->option('dry-run')) {
            try {
                $rawSigned = new EIP1559Transaction($fields)->sign($signer->privateKey());
                $rawHex = str_starts_with($rawSigned, '0x') ? $rawSigned : '0x'.$rawSigned;
                $this->info('Dry-run signed rawTx (not broadcasted):');
                $this->line($rawHex);
            } catch (\Throwable $e) {
                $this->error('Dry-run signing failed: '.$e->getMessage());

                return self::FAILURE;
            }

            return self::SUCCESS;
        }

        try {
            $rawSigned = new EIP1559Transaction($fields)->sign($signer->privateKey());
            $rawHex = str_starts_with($rawSigned, '0x') ? $rawSigned : '0x'.$rawSigned;
            $txHash = $rpc->call('eth_sendRawTransaction', [$rawHex]);
            $this->info('Broadcast replacement txHash: '.$txHash);
            $this->info('Use a block explorer or evm:wait to confirm mining.');
        } catch (\Throwable $e) {
            $msg = $e->getMessage();
            $this->error('Broadcast failed: '.$msg);
            if (str_contains($msg, 'could not replace')) {
                $this->warn('Suggestion: Increase --priority and --max or use --auto with --original=<txHash>.');
                if ($origPriority !== null) {
                    $this->line('Try at least priority >= '.max((int) ($origPriority * 1.15), $origPriority + 2_000_000_000)
                        .' and maxFee >= '.max((int) ($origMaxFee * 1.15), $origMaxFee + 3_000_000_000));
                } else {
                    $this->line('If original tx fees unknown, start with factor 3-5 or specify --priority / --max manually.');
                }
            }

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
