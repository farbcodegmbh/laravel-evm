<?php

use Farbcode\LaravelEvm\Clients\RpcHttpClient;
use Farbcode\LaravelEvm\Exceptions\RpcErrorException;
use Farbcode\LaravelEvm\Exceptions\RpcTransportException;
use Illuminate\Support\Facades\Http;
use kornrunner\Keccak;

const URL_A = 'https://a.example.com/v2/KEY';
const URL_B = 'https://b.example.com/v2/KEY';

function rpcResult(mixed $result): array
{
    return ['jsonrpc' => '2.0', 'id' => 1, 'result' => $result];
}

function rpcError(string $message, int $code = -32000, mixed $data = null): array
{
    $error = ['code' => $code, 'message' => $message];

    if ($data !== null) {
        $error['data'] = $data;
    }

    return ['jsonrpc' => '2.0', 'id' => 1, 'error' => $error];
}

function client(array $urls = [URL_A, URL_B]): RpcHttpClient
{
    return new RpcHttpClient($urls, 137, ['timeout' => 1, 'connect_timeout' => 1, 'tries' => 1]);
}

// --- failover is for transport only -----------------------------------------

it('falls over to the next endpoint when one is unreachable', function () {
    Http::fake([
        'a.example.com/*' => Http::response('gateway down', 502),
        'b.example.com/*' => Http::response(rpcResult('0x1')),
    ]);

    expect(client()->call('eth_blockNumber'))->toBe('0x1');
});

it('stays on the working endpoint for later calls', function () {
    Http::fake([
        'a.example.com/*' => Http::response('gateway down', 502),
        'b.example.com/*' => Http::response(rpcResult('0x1')),
    ]);

    $client = client();
    $client->call('eth_blockNumber');
    Http::fake([
        'a.example.com/*' => Http::response('gateway down', 502),
        'b.example.com/*' => Http::response(rpcResult('0x2')),
    ]);
    $client->call('eth_blockNumber');

    // A broadcast and the polling that follows must not drift between providers
    Http::assertNotSent(fn ($request) => str_contains($request->url(), 'a.example.com'));
});

it('does not repeat a node verdict against every other endpoint', function () {
    Http::fake([
        'a.example.com/*' => Http::response(rpcError('execution reverted', 3)),
        'b.example.com/*' => Http::response(rpcResult('0xdead')),
    ]);

    expect(fn () => client()->call('eth_call', [[]]))->toThrow(RpcErrorException::class);

    Http::assertSentCount(1);
});

it('throws a transport exception only when every endpoint failed', function () {
    Http::fake(['*' => Http::response('gateway down', 502)]);

    expect(fn () => client()->call('eth_blockNumber'))->toThrow(RpcTransportException::class);
});

// --- error fidelity ----------------------------------------------------------

it('keeps the json rpc code and data on the exception', function () {
    Http::fake(['*' => Http::response(rpcError('execution reverted', 3, '0x08c379a0'))]);

    try {
        client([URL_A])->call('eth_call', [[]]);
        $this->fail('expected RpcErrorException');
    } catch (RpcErrorException $e) {
        expect($e->rpcCode)->toBe(3);
        expect($e->rpcData)->toBe('0x08c379a0');
        expect($e->isRevert())->toBeTrue();
    }
});

it('recognises a revert reported without a code', function () {
    Http::fake(['*' => Http::response(rpcError('execution reverted: insufficient balance', -32000))]);

    try {
        client([URL_A])->call('eth_call', [[]]);
        $this->fail('expected RpcErrorException');
    } catch (RpcErrorException $e) {
        expect($e->isRevert())->toBeTrue();
    }
});

it('hands the raw envelope including the error back from callRaw', function () {
    Http::fake(['*' => Http::response(rpcError('execution reverted', 3))]);

    $envelope = client([URL_A])->callRaw('eth_call', [[]]);

    expect($envelope)->toHaveKey('error');
    expect($envelope['error']['code'])->toBe(3);
});

// --- retry policy ------------------------------------------------------------

it('does not retry a 4xx', function () {
    Http::fake(['*' => Http::response('unauthorized', 401)]);

    expect(fn () => new RpcHttpClient([URL_A], 137, ['tries' => 3])->call('eth_blockNumber'))
        ->toThrow(RpcTransportException::class);

    Http::assertSentCount(1);
});

it('retries a 5xx up to the configured number of tries', function () {
    Http::fake(['*' => Http::response('boom', 503)]);

    expect(fn () => new RpcHttpClient([URL_A], 137, ['tries' => 3])->call('eth_blockNumber'))
        ->toThrow(RpcTransportException::class);

    Http::assertSentCount(3);
});

// --- an accepted transaction is not a failure --------------------------------

it('treats "already known" on a broadcast as success and recovers the hash', function () {
    $raw = '0x02f86c0180843b9aca0085174876e800825208941111111111111111111111111111111111111111808081c0';

    Http::fake(['*' => Http::response(rpcError('already known'))]);

    expect(client([URL_A])->call('eth_sendRawTransaction', [$raw]))
        ->toBe(RpcHttpClient::transactionHash($raw));
});

it('treats "nonce too low" on a broadcast as success', function () {
    $raw = '0x02f86c01';

    Http::fake(['*' => Http::response(rpcError('nonce too low'))]);

    expect(client([URL_A])->call('eth_sendRawTransaction', [$raw]))->toStartWith('0x');
});

it('still reports a genuine broadcast failure', function () {
    Http::fake(['*' => Http::response(rpcError('insufficient funds for gas * price + value'))]);

    expect(fn () => client([URL_A])->call('eth_sendRawTransaction', ['0x02f86c01']))
        ->toThrow(RpcErrorException::class);
});

it('derives the transaction hash as keccak of the raw payload', function () {
    $raw = '0x02f86c01';

    expect(RpcHttpClient::transactionHash($raw))
        ->toBe('0x'.Keccak::hash(hex2bin('02f86c01'), 256));
});
