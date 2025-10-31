# Facades

The package registers multiple facades to streamline access to underlying services.

## Available Facades
| Facade | Underlying Binding | Purpose |
|--------|--------------------|---------|
| `LaravelEvm` | `ContractClient` | Primary contract interaction entry point |
| `EvmContract` | `ContractClient` | Alternate name, same behavior |
| `EvmRpc` | `RpcClient` | Raw JSON-RPC calls and health checks |
| `EvmSigner` | `Signer` | Access signing key & related helpers |
| `EvmFees` | `FeePolicy` | Suggest and adjust fees |
| `EvmNonce` | `NonceManager` | Nonce tracking |
| `EvmLogs` | `LogFilterBuilder` | Log filtering and event decoding |

## Using `LaravelEvm`
```php
$contract = LaravelEvm::at('0xContract', file_get_contents('MyContract.abi.json'));
$name = $contract->call('name');
$jobId = $contract->sendAsync('transfer', ['0xRecipient', 100]);
```

## Raw RPC via `EvmRpc`
```php
$blockHex = EvmRpc::call('eth_blockNumber');
$health = EvmRpc::health(); // ['chainId' => 137, 'block' => 12345678]
```

## Fee Suggestions
```php
$fees = EvmFees::suggest(); // implement in your policy
```
(If your current `FeePolicy` does not define `suggest()`, extend it accordingly.)

## Access Private Key (Caution)
```php
$key = EvmSigner::privateKey(); // avoid logging this
```

## Nonce Tracking
```php
$current = EvmNonce::current('0xSignerAddress');
```

## EvmLogs (Log Filtering & Decoding)
Fluent access to log filtering and basic event decoding.

Methods:
- `query()` start a builder.
- `fromBlock($n)` / `toBlock($n)` / `blockHash($hash)` define range.
- `address($addrOrArray)` filter by one or multiple contract addresses.
- `event($signature)` set topic0 via keccak hash.
- `eventByAbi($abi, $name)` resolve signature from ABI.
- `topic($i, $value)` exact match at index.
- `topicAny($i, [...])` OR match alternatives.
- `topicWildcard($i)` allow any topic value at index.
- `padAddress($addr)` helper to produce a 32-byte topic form for indexed addresses.
- `decodeEvent($abi, $log)` return associative array of values.

Example:
```php
$logs = EvmLogs::query()
    ->fromBlock(18_000_000)
    ->toBlock('latest')
    ->address('0xContract')
    ->event('Transfer(address,address,uint256)')
    ->topic(1, LogFilterBuilder::padAddress($sender))
    ->topicAny(2, [LogFilterBuilder::padAddress($recipientA), LogFilterBuilder::padAddress($recipientB)])
    ->get();

$decoded = array_map(fn($l) => LogFilterBuilder::decodeEvent($abi, $l), $logs);
```

Performance tip: For very large ranges split block intervals (e.g. batches of 5k blocks) and merge results.

Security tip: Do not feed unvalidated user input directly into `event()` or `topic()` without sanity checks.

## Static Analysis
The `LaravelEvm` facade includes `@method` annotations for IDE autocomplete and PHPStan awareness.

## Swapping Implementations
Override bindings in a service provider to change runtime behavior while keeping facade usage stable:
```php
$this->app->singleton(\Farbcode\LaravelEvm\Contracts\FeePolicy::class, fn() => new MyAdaptiveFeePolicy());
```

## Testing with Facades
Facades resolve singletons; in tests you can mock underlying binding:
```php
use Illuminate\Support\Facades\App;

App::singleton(ContractClient::class, fn() => new FakeContractClient());
```
Then assertions remain against facade calls.
