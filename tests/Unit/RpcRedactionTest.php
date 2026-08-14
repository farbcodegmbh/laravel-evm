<?php

use Farbcode\LaravelEvm\Clients\RpcHttpClient;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

const ALCHEMY_URL = 'https://polygon-mainnet.g.alchemy.com/v2/SUPERSECRETKEY';

it('reduces a provider url to scheme, host and a marker', function (string $url, string $expected) {
    expect(RpcHttpClient::redact($url))->toBe($expected);
})->with([
    [ALCHEMY_URL, 'https://polygon-mainnet.g.alchemy.com/***'],
    ['https://rpc.example.com/SECRET', 'https://rpc.example.com/***'],
    ['https://rpc.example.com/?apiKey=SECRET', 'https://rpc.example.com/***'],
    ['https://user:pass@rpc.example.com', 'https://rpc.example.com/***'],
    ['http://localhost:8545', 'http://localhost:8545'],
    ['https://rpc.example.com/', 'https://rpc.example.com'],
    ['not a url', '[unparsable url]'],
]);

it('never writes the api key into the log on an rpc level error', function () {
    Http::fake([ALCHEMY_URL => Http::response(['jsonrpc' => '2.0', 'error' => ['code' => -32000, 'message' => 'boom']])]);

    $logged = [];
    Log::listen(function ($message) use (&$logged) {
        $logged[] = json_encode([$message->message, $message->context]);
    });

    try {
        new RpcHttpClient([ALCHEMY_URL], 137)->call('eth_blockNumber');
    } catch (Throwable) {
        // the call is expected to fail; only the log content matters here
    }

    expect($logged)->not->toBeEmpty();
    expect(implode("\n", $logged))->not->toContain('SUPERSECRETKEY');
});

it('never writes the api key into the log on a transport error', function () {
    Http::fake([ALCHEMY_URL => Http::response('gateway down', 502)]);

    $logged = [];
    Log::listen(function ($message) use (&$logged) {
        $logged[] = json_encode([$message->message, $message->context]);
    });

    try {
        new RpcHttpClient([ALCHEMY_URL], 137)->call('eth_blockNumber');
    } catch (Throwable) {
        // expected
    }

    expect($logged)->not->toBeEmpty();
    expect(implode("\n", $logged))->not->toContain('SUPERSECRETKEY');
});

it('does not hand the api key back from health()', function () {
    Http::fake([ALCHEMY_URL => Http::sequence()
        ->push(['jsonrpc' => '2.0', 'result' => '0x89'])
        ->push(['jsonrpc' => '2.0', 'result' => '0x1']),
    ]);

    $health = new RpcHttpClient([ALCHEMY_URL], 137)->health();

    expect($health['rpc_urls'])->toBe('https://polygon-mainnet.g.alchemy.com/***');
    expect(json_encode($health))->not->toContain('SUPERSECRETKEY');
});
