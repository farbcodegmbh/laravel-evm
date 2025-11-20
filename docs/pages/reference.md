# API Reference

## Facades Overview

| Facade      | Underlying Contract | Purpose                                          |
|-------------|---------------------|--------------------------------------------------|
| `Evm`       | `ContractClient`    | Read & write contract functions                  |
| `EvmRpc`    | `RpcClient`         | Raw JSON-RPC calls & health snapshot             |
| `EvmSigner` | `Signer`            | Access signing address & key (private key usage) |
| `EvmNonce`  | `NonceManager`      | Track last used nonce locally                    |
| `EvmFees`   | `FeePolicy`         | Suggest & bump EIP-1559 fees                     |
| `EvmLogs`   | `LogFilterBuilder`  | Query & filter event logs                        |

---

## ContractClient (via `Evm`)

| Method                                          | Args                              | Returns              | Notes                                                                  |
|-------------------------------------------------|-----------------------------------|----------------------|------------------------------------------------------------------------|
| `at(address, abi)`                              | `string`, `array\| string`        | self                 | Set target contract & ABI JSON/array                                   |
| `call(fn, args=[])`                             | `string`, `array`                 | `CallResult\| mixed` | eth_call; hex wrapped for decoding                                     |
| `sendAsync(fn, args=[], opts=[], payload=null)` | `string`, `array`, `array`, mixed | `string`             | Dispatch async job; returns request UUID; payload flows through events |
| `wait(txHash, timeoutSec=120, pollMs=800)`      | `string`, `int`, `int`            | `array\| null`       | Poll receipt until mined/timeout                                       |
| `estimateGas(data, from?)`                      | `string`, `?string`               | `int`                | Uses eth_estimateGas + padding                                         |

### CallResult

| Method         | Returns  | Description                                                                     |
|----------------|----------|---------------------------------------------------------------------------------|
| `raw()`        | `string` | Original 0x hex                                                                 |
| `as(type)`     | mixed    | Decode basic ABI types (string, bytes, uint256/uint, int256/int, bool, address) |
| `__toString()` | `string` | Raw hex when cast to string                                                     |

---

## RpcClient (via `EvmRpc`)

| Method                    | Args              | Returns                         | Notes                       |
|---------------------------|-------------------|---------------------------------|-----------------------------|
| `call(method, params=[])` | `string`, `array` | mixed                           | Generic JSON-RPC request    |
| `health()`                | -                 | `['chainId'=>int,'block'=>int]` | Convenience status snapshot |

---

## Signer (via `EvmSigner`)

| Method         | Returns  | Notes                                   |
|----------------|----------|-----------------------------------------|
| `getAddress()` | `string` | Public address derived from private key |
| `privateKey()` | `string` | Hex private key (handle securely)       |

---

## NonceManager (via `EvmNonce`)

| Method            | Args  | Returns | Notes                 |
|-------------------|-------|---------|-----------------------|
| `last()`          | -     | `int    | null`                 | Last remembered nonce |
| `remember(nonce)` | `int` | void    | Store nonce after use |

---

## FeePolicy (via `EvmFees`)

| Method                                 | Args                                 | Returns                        | Notes                  |
|----------------------------------------|--------------------------------------|--------------------------------|------------------------|
| `suggest(callable $gasPriceFetcher)`   | `callable $gasPriceFetcher`          | `[priorityWei, maxFeeWei]`     | Initial fee suggestion |
| `replace(int $oldPriority, int $oldMax)`| `int $oldPriority, int $oldMax`      | `[priorityWei, maxFeeWei]`     | Replacement bump       |

---

## LogFilterBuilder (via `EvmLogs`)

Start with `EvmLogs::query()` then chain:

| Method                      | Args                       | Returns | Purpose                                     |
|-----------------------------|----------------------------|---------|---------------------------------------------|
| `fromBlock(block)`          | `int\| string`             | self    | Set starting block or 'latest'              |
| `toBlock(block)`            | `int\| string`             | self    | Set end block or 'latest'                   |
| `address(addrOrArray)`      | `string\| array`           | self    | Filter by one or many contract addresses    |
| `event(signature)`          | `string`                   | self    | Set topic0 = keccak256(signature)           |
| `eventByAbi(abiJson, name)` | `array\| string`, `string` | self    | Resolve signature from ABI by function name |
| `topic(index, value)`       | `int`, `string`            | self    | Exact match indexed topic                   |
| `topicAny(index, values)`   | `int`, `array`             | self    | OR match on multiple values                 |
| `topicWildcard(index)`      | `int`                      | self    | Unset filter for that indexed slot          |
| `get()`                     | -                          | `array` | Fetch raw logs array                        |

## Helpers:

| Helper                      | Args                     | Returns  | Description                             |
|-----------------------------|--------------------------|----------|-----------------------------------------|
| `padAddress(address)`       | `string`                 | `string` | Left-pad address to 32-byte topic value |
| `decodeEvent(abiJson, log)` | `array\|string`, `array` | `array`  | Decode indexed + non-indexed params     |

---

## Encoding Helpers

| Class      | Method                 | Returns  | Use Case                                   |
|------------|------------------------|----------|--------------------------------------------|
| `Encoding` | `stringToBytes32(str)` | `string` | Convert UTF-8 string to bytes32 padded hex |

---

## Events

| Event           | When               | Key Data (excerpt)                     |
|-----------------|--------------------|----------------------------------------|
| `TxQueued`      | Job pushed         | to, data, payload                      |
| `TxBroadcasted` | First broadcast ok | txHash, fields, payload                |
| `TxReplaced`    | Fee bump broadcast | oldTxHash, newFields, attempt, payload |
| `TxMined`       | Receipt found      | txHash, receipt, payload               |
| `TxFailed`      | Terminal failure   | to, data, reason, payload              |
| `CallPerformed` | Read executed      | from, address, function, rawResult     |

---

## Configuration Highlights (`config/evm.php`)

| Section               | Key    | Purpose                                |
|-----------------------|--------|----------------------------------------|
| `rpc_urls`            | list   | Failover endpoints                     |
| `chain_id`            | int    | Network id (EIP-155)                   |
| `signer.private_key`  | hex    | Signing key                            |
| `tx.estimate_padding` | float  | Gas safety multiplier                  |
| `tx.confirm_timeout`  | int    | Seconds before considering replacement |
| `tx.max_replacements` | int    | Fee bump attempts limit                |
| `tx.poll_interval_ms` | int    | Receipt poll interval                  |
| `tx.queue`            | string | Queue name for sendAsync jobs          |

---

## Worker Recommendation

Run one worker per signing key:

```bash
php artisan queue:work --queue=evm-send --sleep=0
```

Maintains nonce ordering; for scaling use a distributed nonce manager.

---

## Error Classes

| Class             | Trigger                | Typical Cause            |
|-------------------|------------------------|--------------------------|
| `RpcException`    | RPC call fails         | Network / endpoint error |
| `GasException`    | Gas estimation failure | Revert, invalid data     |
| `SignerException` | Signing issue          | Bad key / chain mismatch |

---

## Security Notes

- Never log private keys.
- Limit queue concurrency.
- Use multiple RPC endpoints for resilience.
- Attach domain payloads to events for traceability.
