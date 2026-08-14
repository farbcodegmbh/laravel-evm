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
use Farbcode\LaravelEvm\Events\TxReverted;
use Farbcode\LaravelEvm\Exceptions\EvmException;
use Farbcode\LaravelEvm\Exceptions\RpcException;
use Farbcode\LaravelEvm\Support\Encoding;
use Farbcode\LaravelEvm\Support\FeeSnapshot;
use Farbcode\LaravelEvm\Support\Receipt;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Sends a transaction non blocking.
 * Handles gas estimation, nonce retrieval, fee suggestion and replacements.
 *
 * The job broadcasts an irreversible action, which shapes two of its settings:
 * it must never be replayed (tries = 1), and it must not run before the
 * database transaction that authorised it has committed (ShouldQueueAfterCommit).
 */
class SendTransaction implements ShouldQueue, ShouldQueueAfterCommit
{
    use InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Never retry. handle() is not idempotent: a second attempt would sign the
     * same call again under the next nonce and execute it twice on chain.
     */
    public int $tries = 1;

    /**
     * Room for the initial confirmation window plus every replacement window.
     * Without this the worker's default 60s timeout kills the job mid-poll,
     * long before the replacement path is ever reached.
     */
    public int $timeout;

    private const TIMEOUT_SLACK_SECONDS = 30;

    /**
     * Every hash signed for this nonce. A replacement does not invalidate its
     * predecessors, so all of them stay in the race until one is mined.
     *
     * @var list<string>
     */
    private array $hashes = [];

    public function __construct(
        public string $address,
        public string $data,
        public array $opts,
        public int $chainId,
        public array $txCfg,
        public mixed $payload = null,
    ) {
        $this->timeout = $this->confirmTimeout() * (1 + $this->maxReplacements()) + self::TIMEOUT_SLACK_SECONDS;
    }

    public function handle(RpcClient $rpc, Signer $signer, NonceManager $nonces, FeePolicy $fees): void
    {
        event(new TxQueued($this->address, $this->data, $this->payload));

        $from = $signer->getAddress();

        $value = Encoding::toHexQuantity($this->opts['value'] ?? 0);

        // Estimate gas with padding
        $est = $rpc->call('eth_estimateGas', [array_filter([
            'from' => $from, 'to' => $this->address, 'data' => $this->data, 'value' => $value,
        ], fn ($v) => $v !== '0x0')]);
        $gas = (int) max(150000, ceil((is_string($est) ? hexdec($est) : (int) $est) * ($this->txCfg['estimate_padding'] ?? 1.2)));

        // Nonce
        $nonce = $nonces->getPendingNonce($from, function () use ($rpc, $from) {
            $n = $rpc->call('eth_getTransactionCount', [$from, 'pending']);

            return hexdec($n);
        });

        // Fees
        [$prio, $max] = $fees->suggest(FeeSnapshot::fromRpc($rpc));

        $fields = [
            'chainId' => $this->chainId,
            'nonce' => $nonce,
            'maxPriorityFeePerGas' => $prio,
            'maxFeePerGas' => $max,
            'gas' => $gas,
            'to' => $this->address,
            'from' => $from,
            'value' => $value,
            'data' => $this->data,
            'accessList' => [],
        ];

        try {
            $txHash = $this->signAndSend($rpc, $signer, $fields);
            $nonces->markUsed($from, $nonce);
            event(new TxBroadcasted($txHash, $fields, $this->payload));
        } catch (Throwable $e) {
            // The node may still have accepted the transaction before the error
            // (a lost response, or "already known" on a retry). Either way the
            // cached nonce can no longer be trusted, so force a re-read.
            $nonces->invalidate($from);

            $this->terminate('rpc_send_error: '.$e->getMessage());

            return;
        }

        $timeout = $this->confirmTimeout();
        $pollMs = $this->pollIntervalMs();
        $maxRep = $this->maxReplacements();

        $replacementsLeft = $maxRep;

        while (true) {
            if ($this->settleIfMined($rpc, $timeout, $pollMs)) {
                return;
            }

            if ($replacementsLeft <= 0) {
                break;
            }

            $attempt = $maxRep - $replacementsLeft + 1;
            $replacementsLeft--;

            [$prio, $max] = $fees->replace($prio, $max);
            $fields['maxPriorityFeePerGas'] = $prio;
            $fields['maxFeePerGas'] = $max;

            event(new TxReplaced($txHash, $fields, $attempt, $this->payload));

            try {
                $txHash = $this->signAndSend($rpc, $signer, $fields);
                event(new TxBroadcasted($txHash, $fields, $this->payload));
            } catch (Throwable $e) {
                // A rejected replacement usually means an earlier transaction
                // for this nonce was mined. Stop replacing, but loop once more
                // so the hashes already in flight get their polling window.
                Log::warning('laravel-evm: replacement broadcast failed', [
                    'attempt' => $attempt,
                    'nonce' => $nonce,
                    'error' => $e->getMessage(),
                ]);

                $replacementsLeft = 0;
            }
        }

        $minedCount = $this->minedNonceCount($rpc, $from);

        // Nothing consumed the nonce, so every transaction we signed for it was
        // dropped. The cache is now one ahead of the chain and would open a gap
        // that leaves all later transactions pending forever.
        if ($minedCount !== null && $minedCount <= $nonce) {
            $nonces->invalidate($from);
        }

        $this->terminate($this->exhaustedReason($minedCount, $nonce, $maxRep, $fields));
    }

    private function signAndSend(RpcClient $rpc, Signer $signer, array $fields): string
    {
        $txHash = $rpc->call('eth_sendRawTransaction', [$signer->sign($fields)]);

        if (! is_string($txHash) || $txHash === '') {
            throw new RpcException('eth_sendRawTransaction did not return a transaction hash');
        }

        $this->hashes[] = $txHash;

        return $txHash;
    }

    /**
     * Poll every hash signed for this nonce until one has a receipt or the
     * window closes. Returns true when the outcome has been reported.
     */
    private function settleIfMined(RpcClient $rpc, int $timeout, int $pollMs): bool
    {
        $deadline = time() + $timeout;

        while (time() < $deadline) {
            foreach ($this->hashes as $hash) {
                try {
                    $receipt = $rpc->call('eth_getTransactionReceipt', [$hash]);
                } catch (Throwable $e) {
                    // The transaction is already broadcast and only the lookup
                    // failed. Letting this escape would abandon a transaction
                    // that is on its way, so keep polling until the deadline.
                    Log::warning('laravel-evm: receipt lookup failed', [
                        'tx' => $hash,
                        'error' => $e->getMessage(),
                    ]);

                    continue;
                }

                if (is_array($receipt) && $receipt !== []) {
                    $this->settle($hash, $receipt);

                    return true;
                }
            }

            usleep($pollMs * 1000);
        }

        return false;
    }

    /**
     * A receipt only proves inclusion. A reverted call has a receipt too, with
     * status 0x0, and must not be reported as mined.
     */
    private function settle(string $txHash, array $receipt): void
    {
        if (Receipt::isReverted($receipt)) {
            event(new TxReverted($txHash, $receipt, $this->payload));

            return;
        }

        event(new TxMined($txHash, $receipt, $this->payload));
    }

    /**
     * Report a terminal failure on both channels: the TxFailed event for domain
     * listeners, and the queue itself so that failed_jobs, Horizon and Telescope
     * show the failure instead of a job that looks like it succeeded.
     */
    private function terminate(string $reason): void
    {
        event(new TxFailed($this->address, $this->data, $reason, $this->payload));

        $this->fail(new EvmException($reason));
    }

    /**
     * How many transactions the chain has confirmed for this account, or null
     * when the lookup itself failed.
     */
    private function minedNonceCount(RpcClient $rpc, string $from): ?int
    {
        try {
            return (int) hexdec((string) $rpc->call('eth_getTransactionCount', [$from, 'latest']));
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * A consumed nonce means one of our transactions did make it on chain and
     * only the receipt lookup kept missing it, which is worth saying out loud.
     */
    private function exhaustedReason(?int $minedCount, int $nonce, int $maxRep, array $fields): string
    {
        $reason = sprintf(
            'no_receipt_after_%d_replacements (last maxFee=%s priority=%s)',
            $maxRep,
            $fields['maxFeePerGas'],
            $fields['maxPriorityFeePerGas'],
        );

        if ($minedCount !== null && $minedCount > $nonce) {
            return $reason.sprintf(' - nonce %d was consumed on chain, one of %d broadcast transactions may have been mined', $nonce, count($this->hashes));
        }

        return $reason;
    }

    private function confirmTimeout(): int
    {
        return (int) ($this->opts['timeout'] ?? $this->txCfg['confirm_timeout'] ?? 120);
    }

    private function pollIntervalMs(): int
    {
        return (int) ($this->opts['poll_ms'] ?? $this->txCfg['poll_interval_ms'] ?? 800);
    }

    private function maxReplacements(): int
    {
        return (int) ($this->opts['max_replacements'] ?? $this->txCfg['max_replacements'] ?? 2);
    }
}
