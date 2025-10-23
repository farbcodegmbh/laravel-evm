# Transactions

This guide explains how transactions are built, signed, broadcast, and monitored.

## Overview
Write operations are asynchronous, processed by the `SendTransaction` queue job. The job encapsulates all complexity: gas estimation, nonce management, fee adjustments, signing, broadcasting and receipt polling.

## Building a Transaction
Fields (EIP-1559):
- `nonce`
- `to`
- `value` (usually `0x0` for contract calls)
- `gas`
- `maxFeePerGas`
- `maxPriorityFeePerGas`
- `data`
- `chainId`

`TxBuilder` produces serialized RLP payload, `Signer` signs it.

## Sending
```php
$jobId = LaravelEvm::at('0xContract', $abi)->sendAsync('transfer', ['0xRecipient', 100]);
```
`jobId` is a generated UUID for tracking (NOT the queue internal ID).

## Gas Estimation
1. ABI encode function call.
2. Call `eth_estimateGas`.
3. Apply padding factor (`estimate_padding`).

If estimation fails a `GasException` is thrown before enqueue or broadcast.

## Nonce Management
Nonce fetched with `eth_getTransactionCount`. Local nonce manager maintains last used value to prevent reuse in sequential jobs.

## Fee Policy
Starting fees drawn from config; replacement attempts increase fees according to policy (e.g., multiplier or additive tip).

## Broadcasting
`eth_sendRawTransaction` is called; failure emits `TxFailed`.

## Receipt Polling
Loop:
- Call `eth_getTransactionReceipt`.
- Sleep `poll_interval_ms` between attempts.
- Timeout triggers fee replacement if attempts remain, otherwise `TxFailed`.

## Replacement Logic
When a transaction hasn't mined within `confirm_timeout` and `max_replacements` not exceeded:
1. Bump fees.
2. Rebuild & sign.
3. Broadcast.
4. Emit `TxReplaced`.

## Events Sequence Example
```
TxQueued → TxBroadcasted → (TxReplaced?)* → TxMined | TxFailed
```

## Waiting for a Receipt
If you already have the transaction hash:
```php
$receipt = LaravelEvm::wait('0xTxHash');
if ($receipt) {
  // success
}
```
Internally performs polling similar to job logic (no fee replacement here).

## Error Handling Summary
| Stage | Error | Outcome |
|-------|-------|---------|
| Gas estimation | RPC failure / revert | Exception thrown |
| Broadcast | RPC rejection | `TxFailed` (event) |
| Receipt polling | Timeout w/o replacements | `TxFailed` |
| Replacement | All attempts fail | `TxFailed` |

## Best Practices
- Keep `max_replacements` low (1-2).
- Monitor `TxFailed` reasons for tuning.
- Avoid sending many transactions from same address concurrently.
- Increase `confirm_timeout` during network congestion.

## Manual RPC Validation
```php
$raw = EvmRpc::call('eth_getTransactionByHash', ['0xTxHash']);
```
Use this for debugging stuck transactions.

## Security Notes
- Never expose private key in logs.
- Validate ABI & arguments before sending user-supplied transactions.

## Extending Fee Replacement Strategy
Implement custom `FeePolicy` with smarter escalation (e.g., exponential backoff, mempool analytics) and bind it.
