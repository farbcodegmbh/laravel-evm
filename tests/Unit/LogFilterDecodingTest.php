<?php

use Farbcode\LaravelEvm\Contracts\RpcClient;
use Farbcode\LaravelEvm\Support\LogFilterBuilder;
use kornrunner\Keccak;

class NullRpc implements RpcClient
{
    public array $filters = [];

    public function call(string $method, array $params = []): mixed
    {
        return null;
    }

    public function callRaw(string $method, array $params = []): array
    {
        return [];
    }

    public function health(): array
    {
        return [];
    }

    public function getLogs(array $filter): array
    {
        $this->filters[] = $filter;

        return [];
    }
}

function evmWord(string $hex): string
{
    return str_pad($hex, 64, '0', STR_PAD_LEFT);
}

function evmTail(string $text): string
{
    return evmWord(dechex(strlen($text))).str_pad(bin2hex($text), 64, '0', STR_PAD_RIGHT);
}

function query(): LogFilterBuilder
{
    return LogFilterBuilder::make(new NullRpc)->fromBlock(1)->toBlock(2);
}

function topicFor(string $signature): string
{
    return '0x'.Keccak::hash($signature, 256);
}

// --- topic filter assembly ---------------------------------------------------

it('keeps a topic set at an index the earlier slots do not fill', function () {
    $filter = query()->topic(2, evmWord('aa'))->build();

    expect($filter['topics'])->toBe([null, null, '0x'.evmWord('aa')]);
});

it('keeps a gap between two set topics', function () {
    $filter = query()->topic(1, evmWord('bb'))->topic(3, evmWord('cc'))->build();

    expect($filter['topics'])->toBe([null, '0x'.evmWord('bb'), null, '0x'.evmWord('cc')]);
});

it('encodes topics as a json array, not an object', function () {
    $filter = query()->topic(2, evmWord('aa'))->build();

    expect(json_encode($filter['topics']))->toStartWith('[');
});

it('drops trailing wildcards', function () {
    $filter = query()->topic(0, evmWord('dd'))->topicWildcard(1)->topicWildcard(2)->build();

    expect($filter['topics'])->toBe(['0x'.evmWord('dd')]);
});

it('omits the topics key when every slot is a wildcard', function () {
    expect(query()->topicWildcard(0)->topicWildcard(1)->build())
        ->not->toHaveKey('topics');
});

// --- event decoding ----------------------------------------------------------

it('decodes a non indexed string from the data tail instead of its offset', function () {
    $abi = [[
        'type' => 'event',
        'name' => 'Message',
        'inputs' => [
            ['name' => 'from', 'type' => 'address', 'indexed' => true],
            ['name' => 'text', 'type' => 'string', 'indexed' => false],
            ['name' => 'id', 'type' => 'uint256', 'indexed' => false],
        ],
    ]];

    $decoded = LogFilterBuilder::decodeEvent($abi, [
        'topics' => [topicFor('Message(address,string,uint256)'), '0x'.evmWord(str_repeat('2', 40))],
        // head: offset to the tail, then id; tail: length + payload
        'data' => '0x'.evmWord('40').evmWord('2a').evmTail('hello'),
    ]);

    expect($decoded)->toBe([
        'from' => '0x'.str_repeat('2', 40),
        'text' => 'hello',
        'id' => '42',
    ]);
});

it('decodes a single non indexed string', function () {
    $abi = [[
        'type' => 'event',
        'name' => 'Note',
        'inputs' => [['name' => 't', 'type' => 'string', 'indexed' => false]],
    ]];

    $decoded = LogFilterBuilder::decodeEvent($abi, [
        'topics' => [topicFor('Note(string)')],
        'data' => '0x'.evmWord('20').evmTail('laravel-evm'),
    ]);

    expect($decoded['t'])->toBe('laravel-evm');
});

it('returns non indexed bytes as hex', function () {
    $abi = [[
        'type' => 'event',
        'name' => 'Blob',
        'inputs' => [['name' => 'b', 'type' => 'bytes', 'indexed' => false]],
    ]];

    $decoded = LogFilterBuilder::decodeEvent($abi, [
        'topics' => [topicFor('Blob(bytes)')],
        'data' => '0x'.evmWord('20').evmWord('4').str_pad('deadbeef', 64, '0', STR_PAD_RIGHT),
    ]);

    expect($decoded['b'])->toBe('0xdeadbeef');
});

it('names unnamed parameters by position instead of collapsing them', function () {
    $abi = [[
        'type' => 'event',
        'name' => 'Pair',
        'inputs' => [
            ['name' => '', 'type' => 'uint256', 'indexed' => false],
            ['name' => '', 'type' => 'uint256', 'indexed' => false],
        ],
    ]];

    $decoded = LogFilterBuilder::decodeEvent($abi, [
        'topics' => [topicFor('Pair(uint256,uint256)')],
        'data' => '0x'.evmWord('1').evmWord('2'),
    ]);

    expect($decoded)->toBe(['arg0' => '1', 'arg1' => '2']);
});

it('reads each indexed parameter from its own topic', function () {
    $abi = [[
        'type' => 'event',
        'name' => 'Transfer',
        'inputs' => [
            ['name' => 'from', 'type' => 'address', 'indexed' => true],
            ['name' => 'to', 'type' => 'address', 'indexed' => true],
            ['name' => 'value', 'type' => 'uint256', 'indexed' => false],
        ],
    ]];

    $decoded = LogFilterBuilder::decodeEvent($abi, [
        'topics' => [
            topicFor('Transfer(address,address,uint256)'),
            '0x'.evmWord(str_repeat('a', 40)),
            '0x'.evmWord(str_repeat('b', 40)),
        ],
        'data' => '0x'.evmWord('8ac7230489e80000'),
    ]);

    expect($decoded)->toBe([
        'from' => '0x'.str_repeat('a', 40),
        'to' => '0x'.str_repeat('b', 40),
        'value' => '10000000000000000000',
    ]);
});

it('returns the keccak hash for an indexed dynamic parameter', function () {
    $abi = [[
        'type' => 'event',
        'name' => 'Tagged',
        'inputs' => [['name' => 'tag', 'type' => 'string', 'indexed' => true]],
    ]];

    $hash = '0x'.Keccak::hash('hello', 256);

    $decoded = LogFilterBuilder::decodeEvent($abi, [
        'topics' => [topicFor('Tagged(string)'), $hash],
        'data' => '0x',
    ]);

    expect($decoded['tag'])->toBe($hash);
});

it('rejects a log that matches no event in the abi', function () {
    $abi = [['type' => 'event', 'name' => 'Note', 'inputs' => [['name' => 't', 'type' => 'string']]]];

    expect(fn () => LogFilterBuilder::decodeEvent($abi, ['topics' => ['0x'.evmWord('ff')], 'data' => '0x']))
        ->toThrow(RuntimeException::class);
});

it('rejects anonymous events rather than returning nothing', function () {
    $abi = [[
        'type' => 'event',
        'name' => 'Hidden',
        'anonymous' => true,
        'inputs' => [['name' => 'v', 'type' => 'uint256', 'indexed' => false]],
    ]];

    expect(fn () => LogFilterBuilder::decodeEvent($abi, [
        'topics' => [topicFor('Hidden(uint256)')],
        'data' => '0x'.evmWord('1'),
    ]))->toThrow(RuntimeException::class);
});
