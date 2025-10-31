<?php

use Farbcode\LaravelEvm\Contracts\RpcClient;
use Farbcode\LaravelEvm\Support\LogFilterBuilder;

class LogsRpcFake implements RpcClient
{
    public array $lastFilter = [];

    public function call(string $method, array $params = []): mixed
    {
        return '0x1';
    }

    public function callRaw(string $method, array $params = []): array
    {
        return ['result' => '0x1'];
    }

    public function health(): array
    {
        return ['chainId' => 137, 'block' => 1];
    }

    public function getLogs(array $filter): array
    {
        $this->lastFilter = $filter;

        return [];
    }
}

it('builds event topic0 from signature', function () {
    $rpc = new LogsRpcFake;
    LogFilterBuilder::make($rpc)->event('Transfer(address,address,uint256)')->get();
    expect($rpc->lastFilter['topics'][0])->toStartWith('0x');
});

it('pads address correctly', function () {
    $p = LogFilterBuilder::padAddress('0x000000000000000000000000000000000000dEaD');
    expect(strlen($p))->toBe(66)->and(substr($p, -40))->toBe('000000000000000000000000000000000000dead');
});

it('chunks large range', function () {
    $rpc = new LogsRpcFake;
    LogFilterBuilder::make($rpc)->fromBlock(100)->toBlock(120)->chunked(5);
    // lastFilter reflects last chunk end
    expect(hexdec($rpc->lastFilter['toBlock']))->toBe(120);
});
