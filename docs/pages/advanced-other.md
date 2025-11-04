# Other Components

## Fee Policy

Controls initial fee suggestion and replacement fee bumping.

| Config Key             | Meaning                                   |
|------------------------|-------------------------------------------|
| `fees.base_multiplier` | Multiplier applied to base fee suggestion |
| `fees.priority_tip`    | Initial maxPriorityFeePerGas value        |
| `tx.max_replacements`  | Limit of fee bump attempts                |

Implement custom policy by binding a new class to `FeePolicy`.

```php
$this->app->singleton(FeePolicy::class, fn() => new MyAdaptivePolicy());
```

## Nonce Management

Local nonce memory prevents collision during sequential sends. Ensure only one worker per signing address. For
horizontal scaling add a distributed (Redis/DB) manager.

## Signer

Encapsulates private key usage; current driver: `private_key`.
Future extensions could allow hardware wallets or vault integration.

## Health Snapshot

Quick connectivity check:

```php
$status = \Farbcode\LaravelEvm\Facades\EvmRpc::health();
```

Gives chain id & latest block number.

## Encoding Helpers

Use `Encoding::stringToBytes32('text')` for static bytes32 arguments.

## Security Considerations

| Concern              | Recommendation                 |
|----------------------|--------------------------------|
| Private key exposure | Keep in env, never log         |
| Nonce race           | Single worker per address      |
| RPC reliability      | Configure multiple endpoints   |
| Fee underpricing     | Monitor `TxReplaced` frequency |

