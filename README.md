# This is my package laravel-evm

[![Latest Version on Packagist](https://img.shields.io/packagist/v/farbcodegmbh/laravel-evm.svg?style=flat-square)](https://packagist.org/packages/farbcodegmbh/laravel-evm)
[![GitHub Tests Action Status](https://img.shields.io/github/actions/workflow/status/farbcodegmbh/laravel-evm/run-tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/farbcodegmbh/laravel-evm/actions?query=workflow%3Arun-tests+branch%3Amain)
[![GitHub Code Style Action Status](https://img.shields.io/github/actions/workflow/status/farbcodegmbh/laravel-evm/fix-php-code-style-issues.yml?branch=main&label=code%20style&style=flat-square)](https://github.com/farbcodegmbh/laravel-evm/actions?query=workflow%3A"Fix+PHP+code+style+issues"+branch%3Amain)
[![Total Downloads](https://img.shields.io/packagist/dt/farbcodegmbh/laravel-evm.svg?style=flat-square)](https://packagist.org/packages/farbcodegmbh/laravel-evm)

A Laravel native EVM client for server side use
RPC via Http facade  ABI encoding  EIP 1559 signing  nonces  jobs  events

## Installation

You can install the package via composer:

```bash
composer require farbcode/laravel-evm
```

You can publish the config file with:

```bash
php artisan vendor:publish --tag="laravel-evm-config"
```


## env
```dotenv
EVM_CHAIN_ID=137
EVM_RPC_1=https://polygon-mainnet.g.alchemy.com/v2/KEY
EVM_PRIVATE_KEY=0xabc123...64hex
QUEUE_CONNECTION=redis
```

## Usage (Quick Glimpse)

```php
use LaravelEvm; // Facade alias defined in composer.json

$abi = file_get_contents(storage_path('app/abi/MyContract.abi.json'));
$contract = LaravelEvm::at('0xYourContract', $abi);

// Read call
$balance = $contract->call('balanceOf', ['0xUser']);

// Write (async)
$jobId = $contract->sendAsync('transfer', ['0xRecipient', 100]);
```

Wait for a known tx hash:
```php
$receipt = $contract->wait('0xTxHash');
```

More examples & full documentation: See the VitePress docs in `docs/pages` or visit the published site.

### Generate new addresses

The package ships with a helper to generate fresh Ethereum keypairs (using `kornrunner/ethereum-address`).

Generate one address (JSON output):

```bash
php artisan evm:address:generate --json
```

Generate 3 addresses (table output):

```bash
php artisan evm:address:generate --count=3
```

Sample JSON response:

```json
[
	{
		"address": "0xAbcDEF1234...",
		"private_key": "0x6f8d...64hex",
		"public_key": "0x04b3...uncompressed"
	}
]
```

Security note: Private keys are shown once. Persist them securely (e.g. Vault, KMS). Never commit them.

### Log Filtering & Event Decoding
```php
use EvmLogs;
use Farbcode\LaravelEvm\Support\LogFilterBuilder;
$abi = file_get_contents(storage_path('app/abi/ERC20.abi.json'));
$logs = EvmLogs::query()
    ->fromBlock(18_000_000)
    ->toBlock('latest')
    ->address('0xToken')
    ->eventByAbi($abi, 'Transfer')
    ->topicAny(1, [LogFilterBuilder::padAddress($addrA), LogFilterBuilder::padAddress($addrB)])
    ->get();
$decoded = array_map(fn($l) => LogFilterBuilder::decodeEvent($abi, $l), $logs);
```

### Facades Overview

Facade aliases (registered in `composer.json`):

| Facade | Binding |
|--------|---------|
| `LaravelEvm` | ContractClient |
| `EvmContract` | ContractClient |
| `EvmRpc` | RpcClient |
| `EvmSigner` | Signer |
| `EvmFees` | FeePolicy |
| `EvmNonce` | NonceManager |
| `EvmLogs` | LogFilterBuilder |

Example usage:
```php
$symbol = \LaravelEvm::at('0xContract', $abi)->call('symbol');
$health = \EvmRpc::health();
$address = \EvmSigner::getAddress();
// Suggest fees (implement suggest() if missing in your FeePolicy)
// $fees = \EvmFees::suggest();
```

### Chainable Casting

`call()` now returns the client instance for fluent casting. Raw hex is stored internally. Use `result()` to fetch it, then `as(type)` to cast.

```php
$contract = \LaravelEvm::at('0xContract', $abi)->call('name');
$raw = $contract->result(); // e.g. 0x0000...
$name = $contract->as('string'); // decoded

$owner = $contract->call('owner')->as('address');
$supply = $contract->call('totalSupply')->as('uint');
$flag = $contract->call('isActive')->as('bool');
```

Supported types: `string`, `address`, `uint|uint256`, `bool`, `bytes32` (returns hex). Unknown types fall back to raw value.

### Signer Robustness & Environment

Address derivation uses secp256k1 (via `kornrunner/ethereum-address`). On some PHP patch builds older GMP versions can throw a `ValueError` during point math with edge-case keys. Recommendations:
1. Use a freshly generated private key (command above) – most issues disappear.
2. Prefer latest PHP patch (8.4.x) where GMP edge cases are fixed.
3. If derivation fails, you will get a `SignerException` with a clear message; do not bypass by hardcoding a mismatched address (nonce and signatures become inconsistent).
4. For read-only calls in future you can implement a custom Signer driver returning only `getAddress()` without signing logic.

If you consistently see overflow errors, open an issue with your PHP version, GMP version, and the (non-sensitive) pattern of the key (do NOT share the full private key). This helps us improve cross-version resilience without adding unsafe fallbacks.

## Testing

```bash
composer test
```

## Changelog

Please see [CHANGELOG](CHANGELOG.md) for more information on what has changed recently.

## Contributing

Please see [CONTRIBUTING](CONTRIBUTING.md) for details.

## Security Vulnerabilities

Please review [our security policy](../../security/policy) on how to report security vulnerabilities.

## Credits

- [Martin Weinschenk](https://github.com/mweinschenk)
- [All Contributors](../../contributors)

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.
