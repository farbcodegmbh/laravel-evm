# Log Querying

Query and filter event logs using the fluent `EvmLogs` facade powered by `LogFilterBuilder`. Supports multi-address
filtering, topic matching (exact, OR, wildcard), ABI-assisted signature resolution, and decoding static event
parameters.

## Core Methods

| Method                          | Purpose                                  |
|---------------------------------|------------------------------------------|
| `fromBlock(block)`              | Start block number or 'latest'           |
| `toBlock(block)`                | End block number or 'latest'             |
| `address(address\|addresses[])` | Filter by one or many contract addresses |
| `event(signature)`              | Set topic0 = keccak256(signature)        |
| `eventByAbi(abi, name)`         | Resolve signature from ABI entries       |
| `topic(index, value)`           | Exact indexed topic match                |
| `topicAny(index, values[])`     | OR match on several values               |
| `topicWildcard(index)`          | Unset a previously set topic filter      |
| `get()`                         | Execute and return raw logs array        |

## Topic Basics

`topic0` always equals `keccak256(EventName(type1,type2,...))`. Indexed parameters appear in subsequent topics in
declaration order.

::: warning Every query needs a block range
`eth_getLogs` requires either `fromBlock`/`toBlock` or `blockHash`. A query
without one throws `InvalidArgumentException`.
:::

## Address Padding Helper

Addresses in topics are 32-byte left-padded hex. Use helper:

```php
$senderTopic = LogFilterBuilder::padAddress('0xSender');
```

## Examples

### Single Event, Sender Filter

```php
use Farbcode\LaravelEvm\Facades\EvmLogs;
use Farbcode\LaravelEvm\Support\LogFilterBuilder;

$logs = EvmLogs::query()
    ->fromBlock(18_000_000)
    ->toBlock('latest')
    ->address('0xToken')
    ->event('Transfer(address,address,uint256)')
    ->topic(1, LogFilterBuilder::padAddress('0xFrom'))
    ->get();
```

### Multiple Addresses + OR Topic

```php
$logs = EvmLogs::query()
    ->fromBlock(18_000_000)
    ->toBlock('latest')
    ->address(['0xTokenA','0xTokenB'])
    ->event('Transfer(address,address,uint256)')
    ->topicAny(2, [LogFilterBuilder::padAddress($addrX), LogFilterBuilder::padAddress($addrY)])
    ->get();
```

### Wildcard Second Indexed Param

```php
$logs = EvmLogs::query()
    ->fromBlock(18_000_000)
    ->toBlock('latest')
    ->event('Approval(address,address,uint256)')
    ->topicWildcard(2) // let spender vary
    ->get();
```

### ABI-Based Signature

```php
$abi = json_decode(file_get_contents($path), true);
$logs = EvmLogs::query()
    ->fromBlock(18_000_000)
    ->toBlock('latest')
    ->eventByAbi($abi, 'Transfer')
    ->get();
```

## Decoding

```php
$decoded = array_map(fn($log) => LogFilterBuilder::decodeEvent($abi, $log), $logs);
```

Returns an associative array of indexed and non-indexed parameters keyed by
name. Supported: `address`, `uintN`, `intN`, `bool`, `bytesN`, and the dynamic
types `string` and `bytes`.

Three things worth knowing:

- **Integers are decimal strings**, not PHP integers. A wei amount does not fit
  in a float, so returning one would silently lose precision.
- **An indexed dynamic parameter yields its keccak hash**, not its value. The
  EVM stores only the hash in a topic, so the value is not recoverable.
- **A log matching no event in the ABI throws**, as does an anonymous event.
  Filter your logs, or catch `RuntimeException`.

Parameters left unnamed in the ABI are keyed `arg0`, `arg1`, and so on.

## Chunking

`get()` sends one request. For a wide range, `chunked()` splits it into
`evm.logs.max_chunk` blocks per request (default 5000) and merges the results:

```php
$logs = EvmLogs::query()->fromBlock(18_000_000)->toBlock(18_500_000)->chunked();
```

