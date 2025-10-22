# Laravel EVM Package - AI Coding Instructions

## Architecture Overview

This is a Laravel package for Ethereum Virtual Machine (EVM) blockchain interactions with server-side focus. The package provides a clean abstraction layer for:
- **Smart contract interaction** (read/write operations)
- **Asynchronous transaction handling** via Laravel queues
- **EIP-1559 transaction signing** with gas fee management
- **Multi-RPC failover** with round-robin load balancing
- **Nonce management** to prevent transaction conflicts

## Core Components

### 1. Service Container Bindings (`LaravelEvmServiceProvider`)
The package uses Laravel's service container with singleton bindings for all major components:
- `RpcClient` → `RpcHttpClient` (handles multiple RPC URLs)
- `ContractClient` → `ContractClientGeneric` (main API interface)  
- `Signer` → `PrivateKeySigner` (transaction signing)
- `NonceManager` → `LocalNonceManager` (prevents nonce conflicts)
- `FeePolicy` → `SimpleFeePolicy` (EIP-1559 gas pricing)

### 2. Contract Interaction Pattern
```php
// Read operations (synchronous)
$contract = Evm::at('0xAddress', $abiJson);
$result = $contract->call('functionName', $args);

// Write operations (asynchronous via queue)
$jobId = $contract->sendAsync('functionName', $args);
```

### 3. Transaction Lifecycle
Write operations use `SendTransaction` job that handles:
1. Gas estimation with configurable padding
2. Nonce retrieval/management  
3. EIP-1559 fee calculation
4. Transaction signing and broadcast
5. Receipt polling with timeout
6. Gas price replacement attempts if stuck
7. Event emission at each stage (`TxQueued`, `TxBroadcasted`, `TxMined`, `TxFailed`)

## Key Development Patterns

### Configuration-Driven Behavior
All critical parameters are configurable via `config/evm.php`:
- Multiple RPC endpoints (`EVM_RPC_1`, `EVM_RPC_2`, etc.)
- Gas estimation padding (`estimate_padding: 1.2`)
- Transaction timeouts and retry limits
- Queue configuration for transaction jobs

### Contract Interface Implementation
Follow the `ContractClient` interface pattern when extending functionality:
- `at()` - sets contract address/ABI
- `call()` - synchronous reads
- `sendAsync()` - asynchronous writes returning job ID
- `wait()` - blocks until transaction receipt

### Error Handling Strategy
The package uses custom exceptions in `src/Exceptions/`:
- `RpcException` for network/RPC issues
- `GasException` for gas estimation problems  
- `SignerException` for signing failures
- Events for transaction state tracking rather than exceptions

## Development Workflows

### Testing
```bash
composer test          # Run Pest test suite
composer analyse       # PHPStan static analysis  
composer format        # Laravel Pint code formatting
```

### Adding New RPC Methods
1. Extend `RpcClient` interface if needed
2. Implement in `RpcHttpClient` with failover logic
3. Add integration tests

### Extending Signer Support
1. Implement `Signer` contract
2. Add driver configuration in `LaravelEvmServiceProvider`
3. Ensure `privateKey()` method availability for transaction signing

### Command Usage Examples
```bash
php artisan evm:call 0xAddress ./abi.json functionName arg1 arg2
php artisan evm:send 0xAddress ./abi.json functionName arg1 arg2  
php artisan evm:wait 0xTransactionHash
php artisan evm:health
```

## Critical Dependencies

- **web3p/web3.php**: ABI encoding/decoding
- **web3p/ethereum-tx**: EIP-1559 transaction creation and signing
- **spatie/laravel-package-tools**: Laravel package scaffolding

## Environment Requirements

- **Queue system required**: Redis recommended for `QUEUE_CONNECTION`
- **Private key management**: Store `EVM_PRIVATE_KEY` securely
- **RPC reliability**: Configure multiple `EVM_RPC_*` endpoints for redundancy
- **Chain ID**: Must match target network (`EVM_CHAIN_ID`)

## Transaction Queue Considerations

- Keep queue concurrency at 1 per signing address to prevent nonce conflicts
- Use dedicated queue (`evm-send`) to isolate blockchain operations
- Monitor transaction events for debugging and logging
- Failed transactions emit `TxFailed` events rather than throwing exceptions