<?php

use Farbcode\LaravelEvm\Events\CallPerformed;
use Farbcode\LaravelEvm\Contracts\RpcClient;
use Farbcode\LaravelEvm\Contracts\Signer;
use Farbcode\LaravelEvm\Contracts\AbiCodec;
use Farbcode\LaravelEvm\Clients\ContractClientGeneric;
use Illuminate\Support\Facades\Event;

class TestSigner implements Signer {
    public function getAddress(): string { return '0xdeadbeefdeadbeefdeadbeefdeadbeefdeadbeef'; }
    public function privateKey(): string { return '0x'.str_repeat('aa',64); }
}
class TestRpc implements RpcClient {
    public function call(string $method, array $params = []): mixed {
        if ($method === 'eth_call') { return '0x0000000000000000000000000000000000000000000000000000000000000020'; }
        return '0x1';
    }
    public function callRaw(string $method, array $params = []): array { return ['result' => '0x1']; }
    public function health(): array { return ['chainId' => 137, 'block' => 1]; }
    public function getLogs(array $filter): array { return []; }
}
class TestAbi implements AbiCodec {
    public function encodeFunction(array|string $abi, string $fn, array $args): string { return '0xabcdef'; }
    public function callStatic(array|string $abi, string $fn, array $args, callable $ethCall): mixed { return $ethCall('0x'); }
}

it('dispatches CallPerformed on read calls', function() {
    Event::fake([CallPerformed::class]);
    $client = new ContractClientGeneric(new TestRpc, new TestSigner, new TestAbi, 137, []);
    $client->at('0xcontract', []);
    $res = $client->call('foo', ['arg1']);
    expect($res)->toBeInstanceOf(\Farbcode\LaravelEvm\Support\CallResult::class);
    Event::assertDispatched(CallPerformed::class, fn(CallPerformed $e) => $e->function === 'foo' && $e->address === '0xcontract');
});
