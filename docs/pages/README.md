# Laravel EVM Package Documentation

Welcome to the official documentation for the Laravel EVM package. This documentation covers installation, configuration, architecture, and practical usage examples for interacting with EVM-compatible blockchains from a Laravel application.

## Contents

- Introduction
- Quick Start
- Installation
- Configuration
- Core Concepts
- Facades Overview
- Contract Interaction
- Transactions & Lifecycle
- Gas & Fee Management (EIP-1559)
- Nonce Management
- RPC Client & Failover
- ABI Encoding
- Queue & Concurrency Guidelines
- Events
- Error Handling Strategy
- Key Generation & Addresses
- Testing Guidance
- Advanced Topics
- Troubleshooting
- Roadmap

---

## Introduction
The Laravel EVM package provides a clean, queue-friendly abstraction layer for interacting with Ethereum and other EVM-compatible chains (e.g., Polygon). It focuses on reliable server-side transaction handling: multi-RPC failover, EIP-1559 dynamic fee adjustment, nonce management, and async job processing.

### Design Goals
- Robust write operations (transaction retries & replacement)
- Minimal boilerplate for contract reads/writes
- Safe nonce handling when using queues
- Observable transaction lifecycle via events
- Extendable architecture (Signer, FeePolicy, RpcClient)

## Quick Start
```php
use LaravelEvm; // facade alias registered by the package

$contract = LaravelEvm::at('0xContractAddress', file_get_contents('MyContract.abi.json'));
$result = $contract->call('balanceOf', ['0xUserAddress']);

$jobId = $contract->sendAsync('transfer', ['0xRecipient', 1000]);
```
Wait for receipt:
```php
$receipt = $contract->wait('0xTxHash'); // polls until mined or timeout
```

## Installation
1. Require the package:
```bash
composer require farbcodegmbh/laravel-evm
```
2. Publish configuration if desired (optional):
```bash
php artisan vendor:publish --tag=laravel-evm-config
```
3. Ensure a queue worker is running (Redis recommended):
```bash
php artisan queue:work --queue=evm-send
```

## Configuration
Located at `config/evm.php`.

Key sections:
- `rpc_urls`: Array of RPC endpoints for failover & load balancing.
- `chain_id`: Target chain ID (e.g. 1 for Ethereum, 137 for Polygon).
- `signer`: Driver and private key (`EVM_PRIVATE_KEY`).
- `fees`: Tuning for base and priority fees.
- `tx`: Operational controls (padding, timeout, poll interval, queue name, max replacements).

Example environment variables:
```
EVM_CHAIN_ID=137
EVM_PRIVATE_KEY=0xYOUR_PRIVATE_KEY
EVM_RPC_1=https://polygon-rpc.com
EVM_RPC_2=https://another-backup-endpoint
```

## Core Concepts
| Concept | Responsibility |
|---------|----------------|
| RpcClient | JSON-RPC communication + failover |
| ContractClient | High-level ABI call & send management |
| Signer | EIP-1559 transaction signing with private key driver |
| NonceManager | Prevents concurrent nonce collisions |
| FeePolicy | Encapsulates fee suggestion & replacement strategy |
| TxBuilder | Constructs EIP-1559 transactions |
| AbiCodec | Encodes function calls into byte data |

## Facades Overview
Facades simplify access to underlying services:
- `LaravelEvm` → `ContractClient`
- `EvmContract` → `ContractClient`
- `EvmRpc` → `RpcClient`
- `EvmSigner` → `Signer`
- `EvmFees` → `FeePolicy`
- `EvmNonce` → `NonceManager`

Example:
```php
$latest = EvmRpc::call('eth_blockNumber');
$policy = EvmFees::suggest();
```

## Contract Interaction
### Acquire Contract Handle
```php
$contract = LaravelEvm::at('0xContract', $abiJson);
```
### Read Calls (eth_call)
```php
$symbol = $contract->call('symbol');
$balance = $contract->call('balanceOf', ['0xUser']);
```
### Write Calls (Async)
```php
$jobId = $contract->sendAsync('approve', ['0xSpender', 5000]);
// jobId is a generated tracking UUID (not the queue internal ID)
```
### Waiting for Receipt
```php
$receipt = $contract->wait('0xTxHash', timeoutSec: 180, pollMs: 1000);
```

## Transactions & Lifecycle
Async transactions are processed by the `SendTransaction` job:
1. Encode function call
2. Estimate gas (+ padding)
3. Fetch nonce
4. Build & sign EIP-1559 transaction
5. Broadcast
6. Poll receipt
7. Replacement attempts if stuck

Events emitted:
- `TxQueued`
- `TxBroadcasted`
- `TxReplaced`
- `TxMined`
- `TxFailed`

Listen in `EventServiceProvider`:
```php
protected $listen = [
    TxBroadcasted::class => [LogTxBroadcast::class],
];
```

## Gas & Fee Management (EIP-1559)
`FeePolicy` suggests base and priority fees. Replacement attempts increase fees when a transaction is pending too long.

Config keys:
- `fees.base_multiplier`
- `fees.priority_tip`
- `tx.max_replacements`

## Nonce Management
`LocalNonceManager` tracks the last nonce used. Recommended: run only one worker per signing address queue to avoid races.

Improvement idea: Redis-backed nonce manager for horizontal scaling.

## RPC Client & Failover
`RpcHttpClient` rotates through configured RPC URLs. Failed attempts are logged and next URL is tried until all fail, then an exception is thrown.

Manual call:
```php
$resultHex = EvmRpc::call('eth_chainId');
```
Health check:
```php
$health = EvmRpc::health(); // ['chainId' => 137, 'block' => 12345678]
```

## ABI Encoding
The package includes a lightweight encoder for common types (address, uint, bool, bytes32, string). For complex types (arrays, tuples, dynamic bytes) you may need to extend `AbiCodec`.

## Queue & Concurrency Guidelines
- Use dedicated `evm-send` queue.
- Ensure sequential processing per address to avoid nonce clashes.
- Monitor replacement attempts to tune fees.

## Events
| Event | Purpose |
|-------|---------|
| TxQueued | Job accepted into queue |
| TxBroadcasted | Initial transaction broadcast |
| TxReplaced | New transaction replaces pending one |
| TxMined | Receipt obtained |
| TxFailed | Terminal failure (broadcast or receipt timeout) |

## Error Handling Strategy
Errors during broadcast or confirmation do not throw inside application flow; they emit `TxFailed`. For RPC or gas estimation misuse, exceptions are thrown early.

## Key Generation & Addresses
Generate a new keypair:
```bash
php artisan evm:address:generate
```
Output includes:
- private_key
- address (checksum)

Store private keys securely (never commit `.env` with secrets).

## Testing Guidance
Use Pest + Testbench:
```bash
composer test
```
Recommended test focus:
- Fee suggestion logic
- Nonce sequencing
- Event emission order
- Failure scenarios (bad RPC, insufficient gas)

## Advanced Topics
### Custom Signer Driver
Implement `Signer` and register in service provider based on config.

### Extending ABI Support
Add a richer encoder by replacing the `AbiCodec` binding with a full-featured implementation.

### Multi-RPC Strategy
The current client does round-robin. You can implement weighted or latency-aware selection by extending `RpcClient`.

## Troubleshooting
| Issue | Cause | Resolution |
|-------|-------|-----------|
| Nonce mismatch | Multiple workers | Limit concurrency or external nonce store |
| Stuck pending | Low fees | Increase priority tip / replacement count |
| RPC errors | Endpoint downtime | Add more RPC URLs / monitor logs |
| Empty receipt | Short timeout | Increase `confirm_timeout` config |

## Roadmap
- Redis nonce manager
- Advanced ABI codec (dynamic types)
- Multi-signer support
- Automatic fee escalator strategy

---

## License
MIT
