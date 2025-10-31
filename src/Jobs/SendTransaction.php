<?php

// src/Jobs/SendTransaction.php

namespace Farbcode\LaravelEvm\Jobs;

use Farbcode\LaravelEvm\Contracts\FeePolicy;
use Farbcode\LaravelEvm\Contracts\NonceManager;
use Farbcode\LaravelEvm\Contracts\RpcClient;
use Farbcode\LaravelEvm\Contracts\Signer;
use Farbcode\LaravelEvm\Events\TxBroadcasted;
use Farbcode\LaravelEvm\Events\TxFailed;
use Farbcode\LaravelEvm\Events\TxMined;
use Farbcode\LaravelEvm\Events\TxQueued;
use Farbcode\LaravelEvm\Events\TxReplaced;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Web3p\EthereumTx\EIP1559Transaction;

/**
 * Sends a transaction non blocking
 * Handles gas estimation nonce retrieval fee suggestion and replacements
 */
class SendTransaction implements ShouldQueue
{
    use InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public string $address, public string $data, public array $opts, public int $chainId, public array $txCfg) {}

    public function handle(RpcClient $rpc, Signer $signer, NonceManager $nonces, FeePolicy $fees): void
    {
        event(new TxQueued($this->address, $this->data));

        $from = $signer->getAddress();

        // Estimate gas with padding
        $est = $rpc->call('eth_estimateGas', [[
            'from' => $from, 'to' => $this->address, 'data' => $this->data,
        ]]);
        $gas = (int) max(150000, ceil((is_string($est) ? hexdec($est) : (int) $est) * ($this->txCfg['estimate_padding'] ?? 1.2)));

        // Nonce
        $nonce = $nonces->getPendingNonce($from, function () use ($rpc, $from) {
            $n = $rpc->call('eth_getTransactionCount', [$from, 'pending']);

            return hexdec($n);
        });

        // Fees
        $gasPriceHex = $rpc->call('eth_gasPrice');
        [$prio, $max] = $fees->suggest(fn () => $gasPriceHex);

        $fields = [
            'chainId' => $this->chainId,
            'nonce' => $nonce,
            'maxPriorityFeePerGas' => $prio,
            'maxFeePerGas' => $max,
            'gas' => $gas,
            'to' => $this->address,
            'from' => $from,
            'value' => 0,
            'data' => $this->data,
            'accessList' => [],
        ];

        $pk = method_exists($signer, 'privateKey') ? $signer->privateKey() : null;
        if (! $pk) {
            event(new TxFailed($this->address, $this->data, 'Signer has no privateKey method'));

            return;
        }

        // First broadcast with error handling
        try {
            $raw = new EIP1559Transaction($fields)->sign($pk);
            $rawHex = str_starts_with($raw, '0x') ? $raw : '0x'.$raw; // ensure 0x prefix
            $txHash = $rpc->call('eth_sendRawTransaction', [$rawHex]);
            $nonces->markUsed($from, $nonce);
            event(new TxBroadcasted($txHash, $fields));

        } catch (\Throwable $e) {
            event(new TxFailed($this->address, $this->data, 'rpc_send_error: '.$e->getMessage()));

            return;
        }

        $timeout = (int) ($this->opts['timeout'] ?? $this->txCfg['confirm_timeout']);
        $pollMs = (int) ($this->opts['poll_ms'] ?? $this->txCfg['poll_interval_ms']);
        $maxRep = (int) ($this->opts['max_replacements'] ?? $this->txCfg['max_replacements']);

        $deadline = time() + $timeout;
        while (time() < $deadline) {
            $rec = $rpc->call('eth_getTransactionReceipt', [$txHash]);
            if (! empty($rec)) {
                event(new TxMined($txHash, $rec));

                return;
            }
            usleep($pollMs * 1000);
        }

        // Replacements if still pending
        for ($i = 0; $i < $maxRep; $i++) {
            $oldTxHash = $txHash;
            [$prio, $max] = $fees->replace($prio, $max);
            $fields['maxPriorityFeePerGas'] = $prio;
            $fields['maxFeePerGas'] = $max;

            // Emit replacement attempt (before rebroadcast)
            event(new TxReplaced($oldTxHash, $fields, $i + 1));

            try {
                $raw = new EIP1559Transaction($fields)->sign($pk);
                $rawHex = str_starts_with($raw, '0x') ? $raw : '0x'.$raw; // ensure 0x prefix
                $txHash = $rpc->call('eth_sendRawTransaction', [$rawHex]);
                event(new TxBroadcasted($txHash, $fields));
            } catch (\Throwable $e) {
                event(new TxFailed($this->address, $this->data, 'rpc_send_error_replacement_'.$i.': '.$e->getMessage()));

                return;
            }

            $deadline = time() + $timeout;
            while (time() < $deadline) {
                $rec = $rpc->call('eth_getTransactionReceipt', [$txHash]);
                if (! empty($rec)) {
                    event(new TxMined($txHash, $rec));

                    return;
                }
                usleep($pollMs * 1000);
            }
        }

        event(new TxFailed(
            $this->address,
            $this->data,
            sprintf('no_receipt_after_%d_replacements (last maxFee=%d priority=%d)', $maxRep, $fields['maxFeePerGas'], $fields['maxPriorityFeePerGas'])
        ));
    }
}
