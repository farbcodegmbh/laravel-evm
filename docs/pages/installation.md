# Installation

## Requirements
- PHP >= 8.4
- Laravel 11, 12 or 13
- GMP PHP extension (`ext-gmp`) installed and enabled

## Package Install
Install via Composer:
```bash
composer require farbcode/laravel-evm
```

## Environment Setup
Define chain id, one or more RPC endpoints, and the private key used for signing transactions:
```
EVM_CHAIN_ID=137
EVM_RPC_1=https://rpc1.example
EVM_RPC_2=https://rpc2.backup
EVM_PRIVATE_KEY=0xYOUR_PRIVATE_KEY
```
Multiple RPC URLs provide failover and load distribution.

## Publish Config (Optional)
Publish the configuration file if you want to adjust gas padding, timeouts or fee strategy:
```bash
php artisan vendor:publish --tag=evm-config
```
This creates `config/evm.php` with sections for `rpc_urls`, `rpc`, `tx`, `fees`,
`logs`, `tracking` and signer settings.

## Transaction Tracking (Optional)
To keep a record of every transaction, publish and run the migration:
```bash
php artisan vendor:publish --tag=evm-migrations
php artisan migrate
```
and set `EVM_TRACKING=true`. Without it the package needs no database.

## Queue Worker (For Write Operations)
Reads (`call`) are synchronous and do not need a queue.
Writes (`sendAsync`) dispatch a `SendTransaction` job. Behavior depends on your queue driver:

- `QUEUE_CONNECTION=sync`: the job runs inline immediately (no worker needed). This works for quick tests.
- `QUEUE_CONNECTION=redis` (or other async driver): a worker MUST be running, otherwise jobs will remain pending.

Recommended production setup (Redis):
```bash
php artisan queue:work --queue=evm-send --sleep=0 --timeout=400
```

`--timeout` matters: the job waits for a receipt and may replace the
transaction, which with the shipped defaults takes up to
`(1 + max_replacements) * confirm_timeout` seconds. The default worker timeout
of 60 seconds would kill it mid-poll. The job declares a matching `$timeout`
itself, so pass a value at least as large on the worker.

Transactions for one signing address are serialised by a cache lock, so a
second worker cannot race the nonce. Running one worker per signing key is
still the simpler setup, but it is no longer the only thing standing between
you and a nonce collision.

## Health Check
Optionally verify connectivity before sending transactions:
```php
$health = \Farbcode\LaravelEvm\Facades\EvmRpc::health();
// [
//   'rpc_urls' => 'https://rpc1.example/***',   // credentials are redacted
//   'chainId'  => 137,
//   'block'    => 12345678,
//   'endpoints' => [
//       'https://rpc1.example/***' => ['chainId' => 137, 'block' => 12345678, 'matchesConfiguredChain' => true],
//   ],
// ]
```
If this fails, review RPC endpoints and network access.

## Finished
You can now make contract read calls immediately and prepare the queue worker for asynchronous write operations.
