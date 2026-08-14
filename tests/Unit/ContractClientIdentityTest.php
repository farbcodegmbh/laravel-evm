<?php

use Farbcode\LaravelEvm\Contracts\ContractClient;
use Farbcode\LaravelEvm\Contracts\RpcClient;
use Farbcode\LaravelEvm\Contracts\Signer;
use Farbcode\LaravelEvm\Facades\Evm;
use Farbcode\LaravelEvm\Jobs\SendTransaction;
use Illuminate\Support\Facades\Queue;

const TOKEN_A = '0x000000000000000000000000000000000000aaaa';
const TOKEN_B = '0x000000000000000000000000000000000000bbbb';

class IdentityRpc implements RpcClient
{
    public array $calls = [];

    public function call(string $method, array $params = []): mixed
    {
        $this->calls[] = [$method, $params];

        return '0x1';
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
        return [];
    }
}

class IdentitySigner implements Signer
{
    public function getAddress(): string
    {
        return '0x0000000000000000000000000000000000000001';
    }

    public function sign(array $fields): string
    {
        return '0xsigned';
    }
}

function identityAbi(): array
{
    return [[
        'type' => 'function',
        'name' => 'transfer',
        'inputs' => [['name' => 'to', 'type' => 'address'], ['name' => 'v', 'type' => 'uint256']],
    ]];
}

beforeEach(function () {
    $this->rpc = new IdentityRpc;
    app()->instance(RpcClient::class, $this->rpc);
    app()->instance(Signer::class, new IdentitySigner);
});

it('gives each contract its own handle', function () {
    $tokenA = Evm::at(TOKEN_A, identityAbi());
    $tokenB = Evm::at(TOKEN_B, identityAbi());

    expect($tokenA)->not->toBe($tokenB);
});

it('keeps an earlier handle pointing at its own contract on reads', function () {
    $tokenA = Evm::at(TOKEN_A, identityAbi());
    Evm::at(TOKEN_B, identityAbi());

    $tokenA->call('transfer', ['0x0000000000000000000000000000000000000002', 1]);

    expect($this->rpc->calls[0][1][0]['to'])->toBe(TOKEN_A);
});

it('keeps an earlier handle pointing at its own contract on writes', function () {
    Queue::fake();

    $tokenA = Evm::at(TOKEN_A, identityAbi());
    Evm::at(TOKEN_B, identityAbi());

    $tokenA->sendAsync('transfer', ['0x0000000000000000000000000000000000000002', 1]);

    Queue::assertPushed(
        SendTransaction::class,
        fn (SendTransaction $job) => $job->address === TOKEN_A
    );
});

it('does not mutate the instance at() was called on', function () {
    $client = app(ContractClient::class);
    $bound = $client->at(TOKEN_A, identityAbi());

    expect($bound)->not->toBe($client);
});

it('resolves a fresh client from the container each time', function () {
    expect(app(ContractClient::class))->not->toBe(app(ContractClient::class));
});
