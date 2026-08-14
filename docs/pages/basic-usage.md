# Basic Usage

This page provides the minimal steps to read (eth_call) and write (async EIP-1559) contract functions using the Laravel
EVM package. For events, log filtering and full API details see the Advanced Usage and Reference pages.

## Contract Handle

```php
$abi = file_get_contents(base_path('abi/ERC20.json'));
$contract = \Farbcode\LaravelEvm\Facades\Evm::at('0xTokenAddress', $abi);
```

`at()` returns a **new** handle bound to that contract; it does not modify the
client it was called on. Two handles are therefore independent:

```php
$usdc = Evm::at('0xA0b8...', $abi);
$dai  = Evm::at('0x6B17...', $abi);

$usdc->call('symbol');   // still USDC
```

## Reads (eth_call)

```php
$symbol = $contract->call('symbol')->as('string');
$balance = $contract->call('balanceOf', ['0xUser'])->as('uint256');
```

Integers come back as **decimal strings**, whatever their size. A wei amount
does not fit in a PHP integer, so returning one would silently lose precision.
Use bcmath or GMP to work with them:

```php
$whole = bcdiv($balance, bcpow('10', '18'), 6);
```

Reads are synchronous and return a `CallResult` wrapper for convenience decoding.

### Decoding Convenience

Any raw hex result from `call()` is wrapped in `CallResult`. Supported `as()` types:

- `string`, `bytes`
- `uint`, `uint8` … `uint256` - returned as a decimal string
- `int`, `int8` … `int256` - returned as a decimal string, negatives resolved
- `bool`
- `address`

`as()` decodes a single return value. A function returning a tuple needs its
own decoding.

Example:

```php
$name = $contract->call('name')->as('string');
$totalSupply = $contract->call('totalSupply')->as('uint256');
$isPaused = $contract->call('paused')->as('bool');
```

If you need the original hex use `->raw()` or cast to string.

#### Error Handling for Reads

A revert and an outage are different problems and have different exceptions:

```php
use Farbcode\LaravelEvm\Exceptions\RpcErrorException;
use Farbcode\LaravelEvm\Exceptions\RpcTransportException;

try {
    $value = $contract->call('someFn');
} catch (RpcErrorException $e) {
    // the node answered: a revert, bad arguments, insufficient funds
    if ($e->isRevert()) {
        report($e->rpcData);   // ABI encoded revert reason, when supplied
    }
} catch (RpcTransportException $e) {
    // no endpoint could be reached - retry later
}
```

Reads should not emit failure events; only `CallPerformed` is emitted on success.

## Writes (Async Transactions)

```php
$requestId = $contract->sendAsync('transfer', ['0xRecipient', '1000000000000000000']);
```

Amounts may be given as an int, a decimal string or `0x`-hex. Pass anything
above `PHP_INT_MAX` as a **string**, not an int.

### Sending Value

For a payable function, put the amount in wei into `$opts`:

```php
$contract->sendAsync('deposit', [], ['value' => '1000000000000000000']);   // 1 ETH
```

### Transactional Safety

`sendAsync()` performs an irreversible action, and the job waits for the
surrounding database transaction to commit before it runs. A rollback therefore
cancels the broadcast rather than racing it.

Writes enqueue a `SendTransaction` job. You need a running queue worker for progress (unless using the sync queue
driver).

### Attaching Context Payload

You can attach any serializable payload (e.g. an Eloquent model instance) that will travel through all lifecycle events:

```php
$order = Order::find(123);
$requestId = $contract->sendAsync('transfer', ['0xRecipient', 1000], [], $order);
```

Each emitted event (`TxQueued`, `TxBroadcasted`, `TxReplaced`, `TxMined`, `TxFailed`) will expose `$payload` so you can correlate blockchain progress with your domain object.

### Tracking a Transaction

With `EVM_TRACKING=true` and the published migration, every transaction is
recorded under the id `sendAsync()` returned:

```php
use Farbcode\LaravelEvm\Models\EvmTransaction;

$requestId = $contract->sendAsync('transfer', [$to, $amount]);

EvmTransaction::where('request_id', $requestId)->first()?->status;
// queued | broadcast | mined | reverted | failed
```

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

Events provide visibility: `TxQueued`, `TxBroadcasted`, `TxReplaced`, `TxMined`,
`TxReverted`, `TxFailed`.

`TxMined` means mined **and** successful. A transaction that was included but
reverted emits `TxReverted` instead: gas was spent and no state change
happened, so it must not be treated as a success.

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
php artisan queue:work --queue=evm-send --timeout=400
```

Transactions for one signing address are serialised by a cache lock, so a
second worker queues behind the first instead of racing the nonce. Running one
worker per signing address is still the simpler setup and keeps ordering
obvious. For higher throughput, add signing addresses rather than processes.

Set `--timeout` at least as high as the job's own: with the shipped defaults a
transaction can take `(1 + max_replacements) * confirm_timeout` seconds before
it gives up.

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
            'timeout' => 400, // must cover confirm_timeout plus every replacement
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
use Farbcode\LaravelEvm\Support\Receipt;

$receipt = $contract->wait('0xTxHash');

if (Receipt::isSuccessful($receipt)) {
    // mined and did not revert
}
```

A receipt only proves that the transaction was included. `Receipt::isReverted()`
tells the two apart. Wait uses polling; no fee replacement logic here.

## Gas Estimation

`estimateGas()` takes encoded calldata, not a result:

```php
$data = app(\Farbcode\LaravelEvm\Contracts\AbiCodec::class)
    ->encodeFunction($abi, 'transfer', ['0xRecipient', '1000']);

$gas = $contract->estimateGas($data);
```

Adds configurable padding to avoid underestimation. `sendAsync()` estimates on
its own, so this is only needed for a cost preview.

## Raw RPC

```php
$block = \Farbcode\LaravelEvm\Facades\EvmRpc::call('eth_blockNumber');
```

Direct access for diagnostics or unsupported methods.

Proceed to Advanced Usage for log filtering, events, payload handling details and custom components.
