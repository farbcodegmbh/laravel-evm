<?php

// src/Jobs/SendTransaction.php

namespace Farbcode\LaravelEvm\Jobs;

use DateTimeInterface;
use Farbcode\LaravelEvm\Contracts\FeePolicy;
use Farbcode\LaravelEvm\Contracts\NonceManager;
use Farbcode\LaravelEvm\Contracts\RpcClient;
use Farbcode\LaravelEvm\Contracts\Signer;
use Farbcode\LaravelEvm\Contracts\TransactionStore;
use Farbcode\LaravelEvm\Events\TxBroadcasted;
use Farbcode\LaravelEvm\Events\TxFailed;
use Farbcode\LaravelEvm\Events\TxMined;
use Farbcode\LaravelEvm\Events\TxQueued;
use Farbcode\LaravelEvm\Events\TxReplaced;
use Farbcode\LaravelEvm\Events\TxReverted;
use Farbcode\LaravelEvm\Exceptions\EvmException;
use Farbcode\LaravelEvm\Exceptions\RpcException;
use Farbcode\LaravelEvm\Models\EvmTransaction;
use Farbcode\LaravelEvm\Support\Encoding;
use Farbcode\LaravelEvm\Support\FeeSnapshot;
use Farbcode\LaravelEvm\Support\NullTransactionStore;
use Farbcode\LaravelEvm\Support\Receipt;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
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
     * maxTries is deliberately not set. WithoutOverlapping releases the job
     * when another transaction for the same signer is in flight, and a release
     * counts as an attempt - with maxTries = 1 the first release would fail the
     * job outright. retryUntil() bounds the lifetime instead.
     *
     * That is only safe because no attempt can broadcast twice: everything
     * after the send is either caught or ends in terminate(), which calls
     * fail() and does not retry. The only work an attempt can repeat is gas
     * estimation, the nonce read and the fee snapshot, none of which touch the
     * chain.
     */
    public function retryUntil(): DateTimeInterface
    {
        return now()->addSeconds($this->timeout * 2 + self::LOCK_RELEASE_SECONDS);
    }

    /**
     * One signing address, one transaction at a time.
     *
     * The documentation asks operators to run a single worker per signing
     * address so that nonces stay ordered. Nothing enforced it, and a second
     * worker - or a Horizon supervisor scaled past one process - silently
     * raced the nonce. A cache lock keyed on the signer enforces it regardless
     * of how many workers are running.
     */
    public function middleware(): array
    {
        return [
            new WithoutOverlapping($this->lockKey())
                ->releaseAfter(self::LOCK_RELEASE_SECONDS)
                // The lock must outlive the job itself, or a killed worker
                // would leave the next transaction free to race it.
                ->expireAfter($this->timeout + 60),
        ];
    }

    private function lockKey(): string
    {
        return 'laravel-evm:signer:'.strtolower(app(Signer::class)->getAddress());
    }

    /**
     * Room for the initial confirmation window plus every replacement window.
     * Without this the worker's default 60s timeout kills the job mid-poll,
     * long before the replacement path is ever reached.
     */
    public int $timeout;

    private const TIMEOUT_SLACK_SECONDS = 30;

    private const LOCK_RELEASE_SECONDS = 5;

    /**
     * Every hash signed for this nonce. A replacement does not invalidate its
     * predecessors, so all of them stay in the race until one is mined.
     *
     * @var list<string>
     */
    private array $hashes = [];

    private ?TransactionStore $store = null;

    /**
     * Persist the current state under the request id sendAsync() handed back,
     * which is what finally makes that id useful for correlation.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function track(array $attributes): void
    {
        $requestId = $this->opts['request_id'] ?? null;

        if ($this->store === null || ! is_string($requestId)) {
            return;
        }

        $this->store->record($requestId, $attributes);
    }

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

    public function handle(RpcClient $rpc, Signer $signer, NonceManager $nonces, FeePolicy $fees, ?TransactionStore $store = null): void
    {
        $this->store = $store ?? new NullTransactionStore;

        event(new TxQueued($this->address, $this->data, $this->payload));

        $from = $signer->getAddress();

        $value = Encoding::toHexQuantity($this->opts['value'] ?? 0);

        $this->track([
            'signer' => $from,
            'to' => $this->address,
            'data' => $this->data,
            'value' => (string) ($this->opts['value'] ?? '0'),
            'status' => EvmTransaction::STATUS_QUEUED,
        ]);

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
            $this->track(['nonce' => $nonce, 'tx_hash' => $txHash, 'hashes' => $this->hashes, 'status' => EvmTransaction::STATUS_BROADCAST]);
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
                $this->track(['tx_hash' => $txHash, 'hashes' => $this->hashes]);
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
            $this->track(['tx_hash' => $txHash, 'receipt' => $receipt, 'status' => EvmTransaction::STATUS_REVERTED]);
            event(new TxReverted($txHash, $receipt, $this->payload));

            return;
        }

        $this->track(['tx_hash' => $txHash, 'receipt' => $receipt, 'status' => EvmTransaction::STATUS_MINED]);
        event(new TxMined($txHash, $receipt, $this->payload));
    }

    /**
     * Report a terminal failure on both channels: the TxFailed event for domain
     * listeners, and the queue itself so that failed_jobs, Horizon and Telescope
     * show the failure instead of a job that looks like it succeeded.
     */
    private function terminate(string $reason): void
    {
        $this->track(['status' => EvmTransaction::STATUS_FAILED, 'reason' => $reason]);

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
