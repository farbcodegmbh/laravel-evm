# Events

Events provide observability into the lifecycle of each asynchronous transaction.

## Event List
| Event | Fired When | Payload Highlights |
|-------|------------|--------------------|
| `TxQueued` | Job dispatched to queue | `request_id`, `address`, `function` |
| `TxBroadcasted` | First transaction broadcast succeeds | `tx_hash`, `nonce`, `fees` |
| `TxReplaced` | Replacement transaction broadcast | `old_tx_hash`, `new_tx_hash`, `attempt` |
| `TxMined` | Receipt is obtained | `tx_hash`, `receipt` |
| `TxFailed` | Terminal failure | `reason`, `attempts` |

## Listening to Events
Register listeners in `EventServiceProvider`:
```php
protected $listen = [
    \Farbcode\LaravelEvm\Events\TxBroadcasted::class => [\App\Listeners\LogTxBroadcast::class],
];
```

Example Listener:
```php
namespace App\Listeners;

use Farbcode\LaravelEvm\Events\TxFailed;
use Illuminate\Support\Facades\Log;

class LogTxFailed
{
    public function handle(TxFailed $event): void
    {
        Log::error('Transaction failed', [
            'reason' => $event->reason,
            'attempts' => $event->attempts,
        ]);
    }
}
```

## Observability Patterns
- Emit metrics (e.g. time from queued to mined).
- Create alerts on repeated `TxFailed` with same reason.
- Track average replacements per successful transaction.

## Correlating Requests
Use the `request_id` returned by `sendAsync` to correlate application-level operations with emitted events (store in DB if needed). The real transaction hash appears only after broadcast.

## Debugging Tips
- If you see multiple `TxReplaced`, fees may be insufficient.
- Absence of `TxMined` within expected window: adjust `confirm_timeout`.
- Frequent `TxFailed` at broadcast: inspect RPC endpoints & gas estimation.

## Security Considerations
Ensure listeners do not log sensitive data (private keys, raw signed payloads). Focus logging on hashes, nonces, fee numbers.
