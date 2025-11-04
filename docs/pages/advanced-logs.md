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
    ->address(['0xTokenA','0xTokenB'])
    ->event('Transfer(address,address,uint256)')
    ->topicAny(2, [LogFilterBuilder::padAddress($addrX), LogFilterBuilder::padAddress($addrY)])
    ->get();
```

### Wildcard Second Indexed Param

```php
$logs = EvmLogs::query()
    ->event('Approval(address,address,uint256)')
    ->topicWildcard(2) // let spender vary
    ->get();
```

### ABI-Based Signature

```php
$abi = json_decode(file_get_contents($path), true);
$logs = EvmLogs::query()->eventByAbi($abi, 'Transfer')->get();
```

## Decoding

Static types decoded via:

```php
$decoded = array_map(fn($log) => LogFilterBuilder::decodeEvent($abi, $log), $logs);
```

Returns associative array including indexed + non-indexed params.

