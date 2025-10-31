# Architecture

This document explains the internal architecture of the Laravel EVM package.

## Overview
The package layers responsibilities into small single-purpose components registered as singletons via the Laravel service container.

## Component Diagram
```
 +-------------------+        +-------------------+
 |   ContractClient  |<-----> |     AbiCodec      |
 +---------+---------+        +---------+---------+
           |                           ^
           v                           |
 +-------------------+        +-------------------+
 |    TxBuilder      |<-----> |     Signer        |
 +---------+---------+        +---------+---------+
           |                           ^
           v                           |
 +-------------------+        +-------------------+
 |    FeePolicy      |        |   NonceManager    |
 +---------+---------+        +---------+---------+
           |                           ^
           v                           |
                +-------------------+
                |     RpcClient     |
                +-------------------+
```

## ContractClient
High-level facade for contract interaction. Delegates low-level concerns:
- Encodes call data using `AbiCodec`.
- Estimation and gas padding.
- Dispatches asynchronous transaction jobs.
- Polling receipts.

## RpcClient
Handles JSON-RPC requests with multi-endpoint round robin failover. Each request gets a unique UUID ID for correlation.

Responsibilities:
- HTTP POST with retry logic.
- Logging failures.
- Health checks (chainId, latest block).

## Signer
Encapsulates the signing key. Current implementation (`PrivateKeySigner`) uses a raw private key from configuration/environment.

Potential extensions:
- Hardware wallet bridge.
- Multi-signature coordinator.
- Vault-backed key retrieval.

## NonceManager
Tracks local last-used nonce to prevent race conditions when multiple transactions are queued serially. In horizontal scaling scenarios, replace with a distributed implementation (e.g., Redis, database row locking).

## FeePolicy
Suggests fees and escalates them for replacements.

Typical strategy:
- Base fee multiplier over network suggestion.
- Priority tip static or dynamic.
- Replacement acceleration factor.

## TxBuilder
Builds an EIP-1559 transaction (fields: nonce, to, value, gas, maxFeePerGas, maxPriorityFeePerGas, data, chainId) and provides helper to hash unsigned payload for diagnostics.

## AbiCodec
Current implementation includes a simplified encoder for common Solidity types enabling stateless function encoding. Replace with a more complete ABI solution for dynamic/complex types.

## Transaction Job Lifecycle
1. Job receives encoded data and options.
2. Nonce fetched from RPC (`eth_getTransactionCount`).
3. Gas estimated with padding (`eth_estimateGas`).
4. Fees suggested via `FeePolicy`.
5. Raw transaction built & signed by `Signer`.
6. Broadcast via `RpcClient` (`eth_sendRawTransaction`).
7. Poll `eth_getTransactionReceipt` until mined or timeout.
8. If timeout & replacements remaining: increase fees, rebuild, sign, rebroadcast.
9. Emit events at each boundary.

## Events
Used for observability:
- `TxQueued`: job queued.
- `TxBroadcasted`: initial broadcast.
- `TxReplaced`: fee bump transaction broadcast.
- `TxMined`: mined with receipt.
- `TxFailed`: irrecoverable failure.

## Extensibility Points
- Replace any component binding in a service provider (e.g., custom `FeePolicy`).
- Add commands for new operational tasks (e.g., gas scanner, pending status monitor).
- Introduce metrics by listening to events.

## Concurrency Model
Queue concurrency MUST be limited per address. Recommended: single worker for signing address or implement distributed nonce locking.

## Security Considerations
- Keep private keys out of source control and logs.
- Consider rate limiting or circuit breaker around problematic RPC endpoints.
- Validate ABI inputs before encoding to avoid injection or malformed data.

## Future Improvements
- Plug-in system for fee strategies.
- Configurable receipt polling backoff.
- Adaptive RPC selection based on latency.

## Log Filtering & Event Decoding
A dedicated fluent builder (`LogFilterBuilder`) powers the `EvmLogs` facade for convenient retrieval and optional decoding of contract events:
- `event('Transfer(address,address,uint256)')` sets topic0 (keccak hash of signature).
- `eventByAbi($abi, 'Transfer')` resolves the signature from an ABI entry.
- `topic(i, value)` / `topicAny(i, [...])` / `topicWildcard(i)` for selective indexed filtering.
- `padAddress($addr)` produces a 32-byte padded address topic value.
- `decodeEvent($abi, $log)` returns associative array of decoded indexed + non-indexed params (static types only).

This keeps raw log querying decoupled from higher level transaction flow and enables post-processing / analytics (e.g. U18 election vote counting) without re‑implementing filtering logic.

## Added Facade: EvmLogs
The `EvmLogs` facade resolves the builder (container binding: `LogFilterBuilder::class`). It integrates seamlessly with other components and uses the already configured `RpcClient` for network access.
