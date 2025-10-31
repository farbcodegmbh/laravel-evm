# Examples

A collection of focused examples demonstrating common tasks with the Laravel EVM package.

## 1. Query Token Balance
```php
$abi = file_get_contents(storage_path('erc20.abi.json'));
$balanceHex = LaravelEvm::at('0xTokenAddress', $abi)->call('balanceOf', ['0xUser']);
```

## 2. Transfer Tokens (Async)
```php
$jobId = LaravelEvm::at('0xToken', $abi)->sendAsync('transfer', ['0xRecipient', 25]);
// Store $jobId if you need correlation before tx hash is known.
```

## 3. Wait for Mined Transaction
```php
$receipt = LaravelEvm::wait('0xTxHash', timeoutSec: 180);
if ($receipt) {
    // Inspect status or logs
}
```

## 4. Manual Gas Estimation
```php
$contract = LaravelEvm::at('0xContract', $abi);
$data = $contract->estimateGas(
    data: $contract->call('encodeFunctionExample'), // adapt to your encoder usage
);
```

## 5. Raw RPC Debugging
```php
$tx = EvmRpc::call('eth_getTransactionByHash', ['0xTxHash']);
$pendingNonceHex = EvmRpc::call('eth_getTransactionCount', ['0xAddress', 'pending']);
```

## 6. Keypair Generation (CLI)
```bash
php artisan evm:address:generate --count=2 --json
```

## 7. Custom Fee Policy Binding
```php
// In a service provider
use Farbcode\LaravelEvm\Contracts\FeePolicy;

$this->app->singleton(FeePolicy::class, fn() => new MyDynamicFeePolicy(config('evm.fees')));
```

## 8. Listening for Transaction Events
```php
protected $listen = [
    \Farbcode\LaravelEvm\Events\TxMined::class => [\App\Listeners\NotifyUser::class],
];
```

## 9. Handling Failures
```php
class LogTxFail {
    public function handle(\Farbcode\LaravelEvm\Events\TxFailed $e) {
        \Log::warning('Tx failed', ['reason' => $e->reason]);
    }
}
```

## 10. Nonce Safety with Single Worker
```bash
php artisan queue:work --queue=evm-send --max-jobs=1000
```
Run only one worker per signing key to avoid nonce duplication.

## 11. Multi-RPC Health Snapshot
```php
$health = EvmRpc::health();
// ['chainId' => 137, 'block' => 12345678]
```

## 12. Replace Component for Testing
```php
use Farbcode\LaravelEvm\Contracts\RpcClient;

$this->app->singleton(RpcClient::class, fn() => new FakeRpcClient());
```

## 13. Aborting Stuck Transactions
If you detect repeated replacements:
```php
// Decide to notify user and stop escalating further.
```

## 14. Fee Escalation Monitoring
Listen to `TxReplaced` and log attempt number and fee delta.

## 15. Fetch and Decode Event Logs
```php
use EvmLogs; // facade
use Farbcode\LaravelEvm\Support\LogFilterBuilder;

$abi = file_get_contents(storage_path('app/abi/ERC20.abi.json'));
$logs = EvmLogs::query()
    ->fromBlock(18_000_000)
    ->toBlock('latest')
    ->address('0xTokenAddress')
    ->eventByAbi($abi, 'Transfer')
    ->topic(1, LogFilterBuilder::padAddress('0xSender'))
    ->get();

$decoded = array_map(fn($l) => LogFilterBuilder::decodeEvent($abi, $l), $logs);
```
OR topic match & wildcard:
```php
$logs = EvmLogs::query()
    ->fromBlock('latest')
    ->address(['0xTokenA','0xTokenB'])
    ->event('Transfer(address,address,uint256)')
    ->topicAny(1, [LogFilterBuilder::padAddress($addr1), LogFilterBuilder::padAddress($addr2)])
    ->topicWildcard(2)
    ->get();
```

Performance tip: split large ranges into windows for high-volume contracts.

---
Next: [Transactions](/pages/transactions) | Previous: [Facades](/pages/facades)
