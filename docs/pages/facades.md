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
| `EvmNonce` | `NonceManager` | Nonce tracking

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
