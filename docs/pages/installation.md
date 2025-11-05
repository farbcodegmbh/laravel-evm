# Installation

## Requirements
- PHP >= 8.4
- Laravel >= 12
- GMP PHP extension installed and enabled

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
This creates `config/evm.php` with sections for `rpc_urls`, `tx`, `fees`, and signer settings.

## Queue Worker (For Write Operations)
Reads (`call`) are synchronous and do not need a queue.
Writes (`sendAsync`) dispatch a `SendTransaction` job. Behavior depends on your queue driver:

- `QUEUE_CONNECTION=sync`: the job runs inline immediately (no worker needed). This works for quick tests.
- `QUEUE_CONNECTION=redis` (or other async driver): a worker MUST be running, otherwise jobs will remain pending.

Recommended production setup (Redis):
```bash
php artisan queue:work --queue=evm-send --sleep=0
```
**Run a single worker per signing key to keep nonce ordering intact.** Advanced lifecycle details are covered under Basic Usage.

## Health Check
Optionally verify connectivity before sending transactions:
```php
$health = \Farbcode\LaravelEvm\Facades\EvmRpc::health(); // ['chainId'=>137,'block'=>12345678]
```
If this fails, review RPC endpoints and network access.

## Finished
You can now make contract read calls immediately and prepare the queue worker for asynchronous write operations.
