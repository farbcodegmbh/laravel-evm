# Events

Transactional events provide lifecycle visibility for asynchronous writes. Use them for logging, metrics, user
notifications, and fee escalation monitoring.

## Event List

| Event           | When Fired            | Key Fields                              | Purpose                             |
|-----------------|-----------------------|-----------------------------------------|-------------------------------------|
| `TxQueued`      | Job dispatched        | request_id, function, address           | Track submission before broadcast   |
| `TxBroadcasted` | First raw tx accepted | tx_hash, nonce, fees                    | Persist hash, start monitoring      |
| `TxReplaced`    | Replacement broadcast | old_tx_hash, new_tx_hash, attempt, fees | Fee bump / speed-up diagnostics     |
| `TxMined`       | Receipt obtained      | tx_hash, receipt                        | Success; update state models        |
| `TxFailed`      | Terminal failure      | reason, attempts                        | Alert; possible manual intervention |
| `CallPerformed` | Read call completed   | from, to, function, raw_result          | Auditing read queries               |

## Example Listener Registration

```php
protected $listen = [
    \Farbcode\LaravelEvm\Events\TxMined::class => [\App\Listeners\NotifyUser::class],
    \Farbcode\LaravelEvm\Events\TxFailed::class => [\App\Listeners\AlertDevTeam::class],
];
```

## Sample Listener

```php
namespace App\Listeners;

use Farbcode\LaravelEvm\Events\TxBroadcasted;
use Illuminate\Support\Facades\Log;

class LogTxBroadcasted
{
    public function handle(TxBroadcasted $e): void
    {
        Log::info('TX broadcasted', [
            'hash' => $e->txHash,
            'nonce' => $e->nonce,
            'fees' => $e->fees,
        ]);
    }
}
```

## Fee Bump Strategy

When a tx has not mined within `confirm_timeout`, and `max_replacements` not exceeded:

1. Policy recalculates higher fees.
2. Replacement signed & broadcast.
3. `TxReplaced` emitted.

Tune in `config/evm.php`:

```php
'tx' => [
  'confirm_timeout' => 120,      // seconds before considering replacement
  'max_replacements' => 2,        // number of bumps allowed
  'poll_interval_ms' => 800,      // receipt poll delay
]
```

## Observability Tips

- Measure time from `TxQueued` to `TxMined` for performance SLA.
- Count replacements to detect fee configuration issues.
- Alert on frequent identical `TxFailed` reasons (RPC or gas problems).
- Log priority fee deltas between attempts.

## Debug Flow Example

```
TxQueued -> TxBroadcasted -> (TxReplaced)* -> TxMined | TxFailed
```

Parenthesis denotes zero or more replacements.

## Handling Failures

On `TxFailed`, inspect `reason`:

- Gas estimation failure: review contract & args.
- Broadcast rejection: fees or nonce invalid.
- Timeout without receipt: consider manual bump or external explorer check.

