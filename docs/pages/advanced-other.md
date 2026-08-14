# Other Components

## Fee Policy

Controls initial fee suggestion and replacement fee bumping.

Fees are derived from the chain: `maxFee = baseFee * base_multiplier + tip`,
where the tip comes from `eth_maxPriorityFeePerGas`. The gwei values are
floors, not the primary source.

| Config Key                | Meaning                                                   |
|---------------------------|-----------------------------------------------------------|
| `fees.base_multiplier`    | Headroom for the base fee rising while the tx waits       |
| `fees.min_priority_gwei`  | Lower bound for the tip (Polygon typically wants 25-30)   |
| `fees.min_maxfee_gwei`    | Lower bound for maxFeePerGas                              |
| `fees.replacement_factor` | Fee bump factor, never applied below the 10% nodes require |
| `tx.max_replacements`     | Limit of fee bump attempts                                |

Implement a custom policy by binding a new class to `FeePolicy`:

```php
use Farbcode\LaravelEvm\Support\FeeSnapshot;

class MyAdaptivePolicy implements FeePolicy
{
    public function suggest(FeeSnapshot $snapshot): array
    {
        return [$priorityWei, $maxFeeWei];   // maxFee must be >= priority
    }

    public function replace(int $oldPriority, int $oldMax): array
    {
        return [$priorityWei, $maxFeeWei];   // both at least 10% above the old values
    }
}

$this->app->singleton(FeePolicy::class, fn() => new MyAdaptivePolicy());
```

## Nonce Management

Local nonce memory prevents collision during sequential sends. Transactions for
one signing address are serialised by a cache lock, so a second worker cannot
race the nonce; for horizontal scaling across machines add a distributed
(Redis/DB) manager.

The cache is dropped and re-read from the chain whenever it can no longer be
trusted - a failed broadcast, or a transaction that was never mined. A custom
`NonceManager` must implement `invalidate(string $address)` for that.

## Signer

Owns the key and performs the signing, so nothing outside it ever holds the
key. Current driver: `private_key`.

A KMS, HSM or remote signer is a plain implementation of the contract:

```php
class KmsSigner implements Signer
{
    public function getAddress(): string { /* ... */ }

    public function sign(array $fields): string
    {
        // return the raw 0x-prefixed payload for eth_sendRawTransaction
    }
}
```

## Health Snapshot

Quick connectivity check:

```php
$status = \Farbcode\LaravelEvm\Facades\EvmRpc::health();
```

Reports chain id and latest block per configured endpoint, with a
`matchesConfiguredChain` flag so a url pointing at the wrong chain is visible.
Endpoint credentials are redacted.

## Encoding Helpers

Use `Encoding::stringToBytes32('text')` for static bytes32 arguments.

## Security Considerations

| Concern              | Recommendation                 |
|----------------------|--------------------------------|
| Private key exposure | Keep in env; the signer never hands it out |
| RPC credentials      | Logged redacted; keep `.env` out of git |
| Nonce race           | Single worker per address      |
| RPC reliability      | Configure multiple endpoints   |
| Fee underpricing     | Monitor `TxReplaced` frequency |

