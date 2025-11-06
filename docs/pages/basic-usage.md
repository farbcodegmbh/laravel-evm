# Basic Usage

This page provides the minimal steps to read (eth_call) and write (async EIP-1559) contract functions using the Laravel
EVM package. For events, log filtering and full API details see the Advanced Usage and Reference pages.

## Contract Handle

```php
$abi = file_get_contents(base_path('abi/ERC20.json'));
$contract = \Farbcode\LaravelEvm\Facades\Evm::at('0xTokenAddress', $abi);
```

Obtain a reusable handle for subsequent calls.

## Reads (eth_call)

```php
$symbol = $contract->call('symbol')->as('string');
$balanceHex = $contract->call('balanceOf', ['0xUser']);
$balance = $balanceHex->as('uint');
```

Reads are synchronous and return a `CallResult` wrapper for convenience decoding.

### Decoding Convenience

Any raw hex result from `call()` is wrapped in `CallResult`. Supported `as()` types:

- `string`, `bytes`
- `uint`, `uint256`
- `int`, `int256`
- `bool`
- `address`

Example:

```php
$name = $contract->call('name')->as('string');
$totalSupply = $contract->call('totalSupply')->as('uint256');
$isPaused = $contract->call('paused')->as('bool');
```

If you need the original hex use `->raw()` or cast to string.

#### Error Handling for Reads

A revert during `eth_call` generally yields an empty or error RPC response; you can catch exceptions around the facade:

```php
try {
  $value = $contract->call('someFn');
} catch (\Throwable $e) {
  // Log & fallback
}
```

Reads should not emit failure events; only `CallPerformed` is emitted on success.

## Writes (Async Transactions)

```php
$requestId = $contract->sendAsync('transfer', ['0xRecipient', 1000]);
```

Writes enqueue a `SendTransaction` job. You need a running queue worker for progress (unless using the sync queue
driver).

### Transaction Job Lifecycle

The queued job executes these steps:

1. ABI encode function + args.
2. Gas estimation with padding.
3. Nonce retrieval (preventing collisions).
4. EIP-1559 fee suggestion.
5. Transaction build & signature.
6. Broadcast to RPC.
7. Receipt polling until mined or timeout.
8. Optional fee bump & replacement attempts.
   Events provide visibility: `TxQueued`, `TxBroadcasted`, `TxReplaced`, `TxMined`, `TxFailed`.

#### Common Write Pitfalls

- Stuck Pending: Increase priority fee.
- Nonce Errors: Ensure only one worker per signing address.

### Queue Configuration & Workers

By default outgoing transactions are placed on the queue name defined in `config/evm.php` (config('evm.tx.queue'), .env:
`EVM_QUEUE`, default: `evm-send`). Ensure your `QUEUE_CONNECTION` (recommended: `redis`) is configured.

If you set the queue driver to `sync`, jobs execute inline and no worker is required. This is acceptable for local
experiments but not recommended in production (no concurrency control, risk of nonce clashes, blocking HTTP requests).

Start a dedicated worker for the send queue:

```bash
php artisan queue:work --queue=evm-send
```

**CRITICAL: Run exactly ONE worker process per signing address for the `evm-send` queue.** This guarantees strict nonce ordering. Multiple concurrent workers sharing the same private key will race nonces.
If you require higher throughput, add additional signing addresses rather than scaling processes for a single key.

### Horizon Example

When using Laravel Horizon, configure a separate supervisor for `evm-send`:

```php
// config/horizon.php (excerpt)
return [
    'defaults' => [
        // ...
   
        'supervisor-2' => [
            'connection' => 'redis',
            'queue' => ['evm-send'],
            'balance' => 'auto',
            'autoScalingStrategy' => 'time',
            'maxProcesses' => 1, // DO NOT raise for same signer; keep 1 to preserve nonce ordering
            'maxTime' => 0,
            'maxJobs' => 0,
            'memory' => 128,
            'tries' => 1,
            'timeout' => 180,
            'nice' => 0,
        ],
    ],
    'environments' => [
        'production' => [
            // ...
            'supervisor-2' => [
                'maxProcesses' => 1,
            ],
        ],
        'local' => [
            // ...
            'supervisor-2' => [
                'maxProcesses' => 1,
            ],
        ],
    ],
];
```
Production can scale the default queue separately while keeping `evm-send` serialized.

### Debugging Writes

If a transaction never appears:

- Verify worker running (`queue:work --queue=evm-send`).
- Check emitted events order.
- Inspect RPC responses (enable verbose logging around `eth_sendRawTransaction`).
- Confirm chain ID and private key match network.
- Bump priority fee.

### Waiting for Receipt

```php
$receipt = $contract->wait('0xTxHash');
if ($receipt) {
    // success
}
```

Wait uses polling; no fee replacement logic here.

## Gas Estimation

```php
$data = $contract->call('symbol')->raw(); // Example encoded data reuse
$gas = $contract->estimateGas($data);
```

Adds configurable padding to avoid underestimation.

## Raw RPC

```php
$block = \Farbcode\LaravelEvm\Facades\EvmRpc::call('eth_blockNumber');
```

Direct access for diagnostics or unsupported methods.

Proceed to Advanced Usage for log filtering and custom components.
