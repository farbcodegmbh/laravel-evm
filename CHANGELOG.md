# Changelog

All notable changes to `laravel-evm` will be documented in this file.

## v0.4.0 - 2026-08-14

This release is the result of a full audit of the package. It fixes a group of defects that produced **wrong values silently** rather than failing — wrong amounts in calldata, wrong balances read back, transactions reported as successful that had reverted — plus the security issue of provider API keys being written to the log.

Several fixes necessarily change behaviour, because keeping the old behaviour means keeping the bug. **Read [UPGRADING.md](https://github.com/farbcodegmbh/laravel-evm/blob/main/UPGRADING.md) before updating.**

### ⚠️ Two changes that can alter what your application computes

**Integers are now decimal strings.** `CallResult::as()` and `LogFilterBuilder::decodeEvent()` used to return a PHP `int` for small values and the *bare hex digits* above roughly 1.15 tokens — while event decoding lost precision past 2^53 entirely. Any arithmetic on those results needs bcmath or GMP now.

```php
// before: int(1000000000000000000)  or  string(16) "1bc16d674ec80000"
// after:  string "1000000000000000000"
$balance = $contract->call('balanceOf', [$user])->as('uint256');

```
**`at()` returns a new handle.** It no longer mutates the client it was called on, and the container binding is no longer a singleton. Two contract handles are now independent — previously the second `at()` silently retargeted the first.

### Fixed

**Amounts above `PHP_INT_MAX` were clamped in calldata.** `transfer($to, '10000000000000000000')` encoded 9.223372036854775807 tokens instead of 10. The same cast turned a hex amount, or a forgotten argument, into `0` — which for a `minOut` or `deadline` silently removes the guard. (#14)

**`TxMined` fired for reverted transactions.** A receipt only proves inclusion; `status` was never read. Listeners crediting balances did so for transactions that had rolled back. Reverts now emit the new `TxReverted`. (#25)

**Provider API keys were written to the log.** All four `Log` calls in the RPC client, and `health()`, emitted the full endpoint url — which carries the key in the path. A single provider outage copied every key into `laravel.log`. (#20)

**`ltrim($x, '0x')` was used as prefix removal in three places.** It takes a character list, so roughly 1 in 16 inputs lost leading digits: private keys were rejected, and `evm:address:generate` printed silently truncated, invalid addresses. (#26)

**Log topic filters were silently dropped.** Filtering on `topic2` alone produced a query with no topic constraint at all, returning every log in the range while looking filtered. Non-indexed `string` and `bytes` event parameters decoded to their ABI offset instead of their value. (#21)

**A successfully broadcast transaction could be reported as failed.** `already known` and `nonce too low` mean the node accepted the transaction; they were treated as endpoint failures and ended in `TxFailed`, with the nonce left unconsumed locally. (#23)

**The nonce cache could wedge a worker permanently.** A lost response or a dropped transaction left the cache out of step with the chain, with no way to resync short of restarting the process. (#19)

**The queue job was not configured for an irreversible action.** No `$timeout` (so the documented workers killed it mid-poll and the replacement path was unreachable), no retry guard, unprotected receipt polling that could orphan a broadcast transaction, and only the newest hash polled after a fee bump — so an original transaction winning the race was reported as failed. (#27)

**Fees ignored `baseFee` and the priority fee**, deriving both caps from `eth_gasPrice` and then letting Polygon-shaped floors dominate, which overpaid several times over on Ethereum. The `maxFee >= priority` invariant was unenforced. (#28)

**The ABI encoder reshaped invalid input instead of rejecting it** — `uint256[]` encoded as the number `1`, a short address padded into a *different* valid address, an over-long one shifting every following word. (#22)

### Added

- **Native value transfers** — `sendAsync($fn, $args, ['value' => '1000000000000000000'])` (#29)
- **Optional transaction tracking** — an `evm_transactions` table keyed on the id `sendAsync()` returns, off by default (#31)
- **One transaction at a time per signing address**, enforced by a cache lock rather than by operator discipline (#30)
- **Laravel 13 support**, with 11, 12 and 13 all covered in CI (#35)
- **`Support\Receipt`** — `isSuccessful()` / `isReverted()` for callers of `wait()`
- **Configurable RPC transport** — `evm.rpc.timeout`, `connect_timeout`, `tries`; plus `evm.logs.max_chunk`, which `chunked()` already read but was never shipped

### Changed

- **`Signer` signs.** The contract gained `sign()`, and `PrivateKeySigner` no longer exposes the key at all — it used to be passed as a function argument, which put it in stack traces. A KMS or HSM signer is now a plain implementation. (#24)
- **RPC errors are typed.** `RpcErrorException` (with `rpcCode`, `rpcData`, `isRevert()`) for a node verdict, `RpcTransportException` for an unreachable endpoint. Both extend `RpcException`. (#23)
- **`NonceManager::invalidate()`** and **`FeePolicy::suggest(FeeSnapshot)`** — custom implementations need updating. (#19, #28)
- **Fee defaults** moved from 50/120 gwei with multiplier 3 to 1/0 with multiplier 2, since the chain now supplies the number. Polygon users should set `EVM_MIN_PRIORITY_GWEI=30`. (#28)
- **Missing GMP no longer takes down the whole application.** The check moved out of the service provider's `register()` phase to where the maths happens, and `ext-gmp` is declared in `composer.json`. (#34, #37)
- **The gas floor is configurable** (`evm.tx.gas_floor`, default 21 000). The hard-coded 150 000 reserved seven times the funds a plain transfer needs. (#34)

### Removed

- `GasException` — documented but never thrown; a failed estimate raises `RpcErrorException`
- `TxBuilder` / `TxBuilderEip1559` — bound in the container, never resolved
- `PrivateKeySigner::privateKey()` — see above

### Under the hood

The test suite went from 14 tests to 165, with no skips — the one skipped test claimed an "ECC signing overflow" that does not exist and asserted nothing. CI now runs PHP 8.4 against Laravel 11, 12 and 13 in both `prefer-lowest` and `prefer-stable`; documentation was corrected against the actual API, including a README quick start that called an undefined class.

### What's Changed

* fix(codec): decode and encode uint256 without precision loss by @mweinschenk in https://github.com/farbcodegmbh/laravel-evm/pull/14
* fix(tx): treat reverted receipts as failures by @mweinschenk in https://github.com/farbcodegmbh/laravel-evm/pull/25
* fix(crypto): strip the 0x prefix correctly by @mweinschenk in https://github.com/farbcodegmbh/laravel-evm/pull/26
* fix(container): give each contract its own client instance by @mweinschenk in https://github.com/farbcodegmbh/laravel-evm/pull/17
* fix(jobs): make the transaction job queue-safe by @mweinschenk in https://github.com/farbcodegmbh/laravel-evm/pull/27
* fix(nonce): recover from a desynchronised nonce cache by @mweinschenk in https://github.com/farbcodegmbh/laravel-evm/pull/19
* fix(rpc): keep endpoint credentials out of logs by @mweinschenk in https://github.com/farbcodegmbh/laravel-evm/pull/20
* fix(logs): repair topic filters and event decoding by @mweinschenk in https://github.com/farbcodegmbh/laravel-evm/pull/21
* fix(codec): validate contract inputs by @mweinschenk in https://github.com/farbcodegmbh/laravel-evm/pull/22
* fix(rpc): classify errors and fix the retry policy by @mweinschenk in https://github.com/farbcodegmbh/laravel-evm/pull/23
* refactor(signer): move signing behind the Signer contract by @mweinschenk in https://github.com/farbcodegmbh/laravel-evm/pull/24
* fix(fees): derive EIP-1559 fees from baseFee and priority fee by @mweinschenk in https://github.com/farbcodegmbh/laravel-evm/pull/28
* feat(tx): support native value transfers by @mweinschenk in https://github.com/farbcodegmbh/laravel-evm/pull/29
* feat(jobs): enforce one worker per signing address by @mweinschenk in https://github.com/farbcodegmbh/laravel-evm/pull/30
* feat(tx): add optional transaction tracking by @mweinschenk in https://github.com/farbcodegmbh/laravel-evm/pull/31
* test: repair the skipped replacement test and tighten address coverage by @mweinschenk in https://github.com/farbcodegmbh/laravel-evm/pull/32
* docs: align documentation with the actual API by @mweinschenk in https://github.com/farbcodegmbh/laravel-evm/pull/33
* chore: declare ext-gmp, widen CI and remove dead code by @mweinschenk in https://github.com/farbcodegmbh/laravel-evm/pull/34
* build: support laravel 13 by @mweinschenk in https://github.com/farbcodegmbh/laravel-evm/pull/35, superseding the constraint change by @jsperrer in https://github.com/farbcodegmbh/laravel-evm/pull/13
* chore(deps): bump dependabot/fetch-metadata to v3.1.0 by @mweinschenk in https://github.com/farbcodegmbh/laravel-evm/pull/36
* fix: check for GMP where it is used, not at boot by @mweinschenk in https://github.com/farbcodegmbh/laravel-evm/pull/37
* chore(deps): bump actions/checkout from 5 to 6 by @dependabot[bot] in https://github.com/farbcodegmbh/laravel-evm/pull/7
* chore(deps): bump dependabot/fetch-metadata from 2.4.0 to 2.5.0 by @dependabot[bot] in https://github.com/farbcodegmbh/laravel-evm/pull/8
* chore(deps): bump ramsey/composer-install from 3 to 4 by @dependabot[bot] in https://github.com/farbcodegmbh/laravel-evm/pull/9
* chore(deps): bump actions/deploy-pages from 4 to 5 by @dependabot[bot] in https://github.com/farbcodegmbh/laravel-evm/pull/10
* chore(deps): bump actions/configure-pages from 5 to 6 by @dependabot[bot] in https://github.com/farbcodegmbh/laravel-evm/pull/11

**Full Changelog**: https://github.com/farbcodegmbh/laravel-evm/compare/v0.3.0...v0.4.0

## v0.3.0 - 2025-11-20

### What's Changed

* event payload support for async EVM transactions by @mweinschenk in https://github.com/farbcodegmbh/laravel-evm/pull/6

**Full Changelog**: https://github.com/farbcodegmbh/laravel-evm/compare/v0.2.2...v0.3.0

## v0.2.2 - 2025-11-20

### What's Changed

* chore: fix docs, improved default values for transaction fee by @mweinschenk in https://github.com/farbcodegmbh/laravel-evm/pull/3
* chore(deps): update kornrunner/ethereum-address requirement from ^0.3.0 to ^0.4.0 by @dependabot[bot] in https://github.com/farbcodegmbh/laravel-evm/pull/4
* V0.2.2 by @mweinschenk in https://github.com/farbcodegmbh/laravel-evm/pull/5

**Full Changelog**: https://github.com/farbcodegmbh/laravel-evm/compare/v0.2.1...v0.2.2

## v0.2.1 - 2025-11-05

**Full Changelog**: https://github.com/farbcodegmbh/laravel-evm/compare/v0.2.0...v0.2.1

## v0.2.0 - 2025-11-05

**Full Changelog**: https://github.com/farbcodegmbh/laravel-evm/compare/v0.1.0...v0.2.0
