# Configuration Guide

The package reads most runtime behavior from `config/evm.php`. This guide explains each key and recommended values.

## Environment Variables
Typical `.env` entries:
```
EVM_CHAIN_ID=137
EVM_PRIVATE_KEY=0xabcdef... # NEVER commit real keys
EVM_RPC_1=https://polygon-rpc.com
EVM_RPC_2=https://backup-polygon.example
```

You may define additional RPC URLs (`EVM_RPC_3`, `EVM_RPC_4`, ...) and combine them into `rpc_urls` inside the config file.

## Config Sections

### rpc_urls
Array of endpoints used in rotation. Provide at least two for resilience.

### chain_id
Numeric network identifier. Examples:
- 1 (Ethereum Mainnet)
- 137 (Polygon)
- 11155111 (Ethereum Sepolia)

### signer
```
'signer' => [
  'driver' => 'private_key',
  'private_key' => env('EVM_PRIVATE_KEY'),
],
```
Swap `driver` when introducing new signer types.

### fees
Controls fee suggestion & escalation.
Possible keys (example structure):
```
'fees' => [
  'base_multiplier' => 1.10,   // multiply network base fee
  'priority_tip'    => '3gwei', // convert later to wei
  'replacement_bump'=> 1.25,    // factor on each replacement
],
```
When implementing a custom policy, the array is passed to your `FeePolicy`.

### tx
Operational tuning:
```
'tx' => [
  'estimate_padding' => 1.2,    // applied after eth_estimateGas
  'confirm_timeout'  => 120,    // seconds until failure
  'max_replacements' => 2,      // fee bump attempts
  'poll_interval_ms' => 800,    // receipt polling interval
  'queue'            => 'evm-send', // queue name
],
```
Adjust `confirm_timeout` upward for congested networks.

## Private Key Management
Never store clear-text private keys in version control. Recommended strategies:
- Inject via environment variable in deployment pipeline.
- Use secrets manager (AWS Secrets Manager, Vault) and load at runtime.
- Rotate keys when possible.

## Multi-RPC Strategy
Order of URLs defines iteration sequence. All failures produce a single `RpcException` summarizing the final error. Use diverse providers to minimize correlated outages.

## Nonce Strategy
Local nonce manager only safe with single worker per key. For multiple workers, implement a distributed nonce manager and bind it in `AppServiceProvider`.

## Fee Strategy Tuning
- Increase `priority_tip` during high congestion.
- Adjust `replacement_bump` to accelerate stuck transactions (beware of spamming network).
- Monitor average confirmation times via emitted events.

## Observability
Listen to events and attach logging / metrics. Example adding to `EventServiceProvider`:
```php
use Farbcode\LaravelEvm\Events\TxBroadcasted;
use App\Listeners\LogTxBroadcast;

protected $listen = [
    TxBroadcasted::class => [LogTxBroadcast::class],
];
```

## Hardening Tips
- Set HTTP client timeout low (already 10s) and rely on retries.
- Provide at least 2 RPC endpoints.
- Keep `max_replacements` conservative (<3).
- Use a dedicated queue with controlled concurrency.

## Updating Configuration
After changes, clear config cache:
```bash
php artisan config:clear
php artisan config:cache
```
