# Upgrading

## From 0.3.x

This release fixes a set of defects that silently produced wrong values rather
than failing, so several of the corrections necessarily change behaviour. Every
change below is deliberate; none of it can be opted out of, because keeping the
old behaviour means keeping the bug.

Read the two **Correctness** entries first — they are the ones that can change
what your application computes.

---

### Correctness: integers are decimal strings

`CallResult::as()` and `LogFilterBuilder::decodeEvent()` return integers as
decimal strings, always.

Previously `as('uint256')` returned a PHP `int` below roughly 1.15 tokens (16
significant hex digits) and the **bare hex digits** above it, while event
decoding used `hexdec()` and produced a float past 2^53. Both silently
disagreed with the chain for ordinary amounts.

```php
// before: int(1000000000000000000) or string(16) "1bc16d674ec80000"
// after:  string "1000000000000000000"
$balance = $contract->call('balanceOf', [$user])->as('uint256');
```

**What to change.** Anything doing arithmetic on the result. Use bcmath or GMP
rather than casting:

```php
- $whole = $balance / 1e18;
+ $whole = bcdiv($balance, bcpow('10', '18'), 6);
```

Comparisons against a numeric literal still work (`bccomp`), but `(int)` on a
wei amount will truncate.

### Correctness: `at()` returns a new handle

`ContractClient::at()` no longer mutates the instance it is called on, and the
container binding is no longer a singleton. Two handles are now independent.

```php
// before: $tokenA and $tokenB were the same object, both pointing at DAI
$tokenA = Evm::at($usdc, $abi);
$tokenB = Evm::at($dai, $abi);
```

**What to change.** Code that discarded the return value:

```php
- $client->at($address, $abi);
- $client->call('symbol');
+ $contract = $client->at($address, $abi);
+ $contract->call('symbol');
```

The documented style (`$contract = Evm::at(...)`) was already correct.

---

### `TxMined` no longer fires for reverted transactions

A reverted transaction emits the new `TxReverted` event. Listeners that treated
any receipt as success were crediting balances for transactions that rolled
back.

**What to change.** Nothing, if `TxMined` is all you listen for — it is now
accurate. Add a `TxReverted` listener to handle the failure case. For
`wait()`, which still returns the raw receipt, use the new helper:

```php
+ use Farbcode\LaravelEvm\Support\Receipt;

- if ($receipt) { /* success */ }
+ if (Receipt::isSuccessful($receipt)) { /* success */ }
```

### Invalid input now throws

The ABI encoder accepted almost anything and reshaped it silently. These now
raise `InvalidArgumentException` or `RuntimeException`:

- array and tuple parameter types (previously `uint256[]` encoded as the number `1`)
- a wrong number of arguments (previously the missing one encoded as `0`)
- a malformed address or `bytesN` (previously padded into a *different* valid value)
- `bytes` with an odd hex length
- a non-boolean for a `bool` parameter
- an ambiguous overloaded function name

Pass a full signature to choose between overloads:

```php
$contract->sendAsync('safeTransferFrom(address,address,uint256)', [$from, $to, $id]);
```

### RPC errors are typed

`RpcTransportException` means no endpoint could be reached. `RpcErrorException`
means the node answered with a JSON-RPC error and carries `rpcCode`, `rpcData`
and `isRevert()`. Both extend `RpcException`, so existing `catch` blocks keep
working.

`callRaw()` now returns error envelopes instead of failing over past them.

### Removed classes

Both were dead: `GasException` was never thrown anywhere in the package, and
`TxBuilder` / `TxBuilderEip1559` were bound in the container but never
resolved — signing now lives behind the `Signer` contract.

| Removed | Use instead |
| --- | --- |
| `GasException` | `RpcErrorException`, which a failed estimate already raises |
| `TxBuilder`, `TxBuilderEip1559` | `Signer::sign()` |

### Gas floor

The hard-coded 150 000 gas lower bound is now `evm.tx.gas_floor`, defaulting to
21 000. The node reserves `maxFeePerGas * gas` from the balance regardless of
what is actually consumed, so the old floor tied up seven times the funds a
plain transfer needs. `estimateGas()` no longer applies a floor at all — it is
a cost preview, so the padded estimate stands on its own.

### Contract changes

Custom implementations need updating:

| Contract | Change |
| --- | --- |
| `Signer` | add `sign(array $fields): string`; `PrivateKeySigner::privateKey()` is gone |
| `NonceManager` | add `invalidate(string $address): void` (a no-op body is fine) |
| `FeePolicy` | `suggest()` takes a `FeeSnapshot` instead of a `callable` |

### Fee defaults changed

Fees are derived from the chain (`maxFee = baseFee * multiplier + tip`, tip from
`eth_maxPriorityFeePerGas`), and the gwei settings are now floors rather than
the primary source. Defaults moved from `50`/`120` gwei with multiplier `3` to
`1`/`0` with multiplier `2`.

**What to change.** On a chain that needs a minimum tip to be picked up, set it
explicitly — Polygon typically wants:

```dotenv
EVM_MIN_PRIORITY_GWEI=30
```

### Worker timeout

The job now declares a `$timeout` covering the confirmation window plus every
replacement. Raise the worker's timeout to match, or it will be killed
mid-poll:

```bash
php artisan queue:work --queue=evm-send --timeout=400
```

Horizon supervisors need the same on their `timeout` key.

### `health()` output

Credentials are redacted, and the result gained an `endpoints` key reporting
chain id and block per endpoint. `chainId` and `block` now come from one
endpoint rather than possibly two different ones.

---

## New, optional

- **Value transfers** — `sendAsync($fn, $args, ['value' => '1000000000000000000'])`
- **Transaction tracking** — `php artisan vendor:publish --tag="evm-migrations"`, then `EVM_TRACKING=true`
- **Signer lock** — transactions per signing address are serialised automatically
- **RPC tuning** — `evm.rpc.timeout`, `evm.rpc.connect_timeout`, `evm.rpc.tries`
- **`evm.logs.max_chunk`** — read by `chunked()` before, but never shipped in the config file
