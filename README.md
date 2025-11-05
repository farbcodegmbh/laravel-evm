<div align="center">
<h1>Laravel EVM (Ethereum Virtual Machine)</h1>

[![Latest Version on Packagist](https://img.shields.io/packagist/v/farbcodegmbh/laravel-evm.svg?style=flat-square)](https://packagist.org/packages/farbcodegmbh/laravel-evm)
[![GitHub Tests Action Status](https://img.shields.io/github/actions/workflow/status/farbcodegmbh/laravel-evm/run-tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/farbcodegmbh/laravel-evm/actions?query=workflow%3Arun-tests+branch%3Amain)
[![GitHub Code Style Action Status](https://img.shields.io/github/actions/workflow/status/farbcodegmbh/laravel-evm/fix-php-code-style-issues.yml?branch=main&label=code%20style&style=flat-square)](https://github.com/farbcodegmbh/laravel-evm/actions?query=workflow%3A"Fix+PHP+code+style+issues"+branch%3Amain)
[![Total Downloads](https://img.shields.io/packagist/dt/farbcodegmbh/laravel-evm.svg?style=flat-square)](https://packagist.org/packages/farbcodegmbh/laravel-evm)

Simple, Reliable Ethereum Integration for Laravel
    <picture>
        <img alt="Laravel EVM Logo" src="https://raw.githubusercontent.com/farbcodegmbh/laravel-evm/HEAD/art/laravel-evm-ethereum-virtual-machine.png"  style="max-width: 25rem;">
    </picture>
</div>

## Features

- 💡 **EIP-1559 Support:** Seamlessly handle modern Ethereum transactions with dynamic fee management.
- 🚀 **Asynchronous Transaction Queue:** Process blockchain transactions safely through Laravel Queues — no blocking, no delays.
- 🔗 **Event-driven Workflow:** Stay in control with Laravel Events for every step: TxQueued, TxBroadcasted, TxMined, TxFailed.
- 🧠 **Smart Nonce & Fee Strategy:** Automatic nonce tracking and adaptive fee logic for consistent, reliable execution.

## Documentation

All information on how to use this package can be found on our official documentation website.
[→ Read the Docs](https://laravel-evm.farbcode.net)


## Installation

Install the package via composer:

```bash
composer require farbcode/laravel-evm
```

You can publish the config file with:

```bash
php artisan vendor:publish --tag="laravel-evm-config"
```

Then set your blockchain RPC URL, chain id and private key in .env:

```dotenv
EVM_CHAIN_ID=137
EVM_RPC_1=https://polygon-mainnet.g.alchemy.com/v2/KEY
EVM_PRIVATE_KEY=0xabc123...64hex
```

## Usage (Quick Glimpse)

```php
use Farbcode\LaravelEvm\Facades\Evm;

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

### Log Filtering & Event Decoding
```php
use Farbcode\LaravelEvm\Facades\EvmLogs;
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

All information on how to use this package can be found on our official documentation website.
[→ Read the Docs](https://laravel-evm.farbcode.net)

## Changelog

Notable changes to this package are documented in our changelog for every new release.

[→ See what's changed](CHANGELOG.md)

## Contributing

We welcome contributions to this package.

[→ Read our Contribution Guidelines](CONTRIBUTING.md)

[→ Open an Issue](https://github.com/farbcodegmbh/laravel-evm/issues)

[→ Submit a Pull Request](https://github.com/farbcodegmbh/laravel-evm/pulls)

## License

The MIT License (MIT). See [License File](LICENSE.md) for more information.

---

<a href="https://farbcode.net" target="_blank">
    <picture>
        <source media="(prefers-color-scheme: dark)" srcset="https://raw.githubusercontent.com/farbcodegmbh/laravel-evm/HEAD/art/farbcode-logo-dark.png">
        <source media="(prefers-color-scheme: light)" srcset="https://raw.githubusercontent.com/farbcodegmbh/laravel-evm/HEAD/art/farbcode-logo-light.png">
        <img alt="farbcode Logo" src="https://raw.githubusercontent.com/farbcodegmbh/laravel-evm/HEAD/art/farbcode-logo-light.png"  style="max-width: 25rem;">
    </picture>
</a>

Made with ❤️ by [//farbcode](https://farbcode.net).
