# Events

Transactional events provide lifecycle visibility for asynchronous writes. Use them for logging, metrics, user
notifications, and fee escalation monitoring. Each write-related event includes an optional `$payload` that you can attach via `sendAsync(..., $payload)`.

## Event List

| Event           | When Fired            | Key Fields (excerpt)                | Purpose                             |
|-----------------|-----------------------|-------------------------------------|-------------------------------------|
| `TxQueued`      | Job dispatched        | to, data, payload                   | Track submission before broadcast   |
| `TxBroadcasted` | First raw tx accepted | txHash, fields(fees,nonce), payload | Persist hash, start monitoring      |
| `TxReplaced`    | Replacement broadcast | oldTxHash, newFields, attempt, payload | Fee bump / speed-up diagnostics     |
| `TxMined`       | Receipt obtained      | txHash, receipt, payload            | Success; update state models        |
| `TxFailed`      | Terminal failure      | to, data, reason, payload           | Alert; possible manual intervention |
| `CallPerformed` | Read call completed   | from, address, function, rawResult  | Auditing read queries               |

`payload` is `mixed` and can hold any serializable context (e.g. Eloquent model) to correlate app state and blockchain lifecycle.

## Attaching Payload Example

```php
$order = Order::find(123);
$contract->sendAsync('transfer', ['0xRecipient', 1000], [], $order);
```

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
            'nonce' => $e->fields['nonce'] ?? null,
            'fees' => [
                'maxFeePerGas' => $e->fields['maxFeePerGas'] ?? null,
                'maxPriorityFeePerGas' => $e->fields['maxPriorityFeePerGas'] ?? null,
            ],
            'payload_id' => method_exists($e->payload, 'getKey') ? $e->payload->getKey() : null,
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
