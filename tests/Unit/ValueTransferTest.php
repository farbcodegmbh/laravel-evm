<?php

use Farbcode\LaravelEvm\Contracts\RpcClient;
use Farbcode\LaravelEvm\Contracts\Signer;
use Farbcode\LaravelEvm\Events\TxBroadcasted;
use Farbcode\LaravelEvm\Facades\Evm;
use Farbcode\LaravelEvm\Jobs\SendTransaction;
use Farbcode\LaravelEvm\Support\Encoding;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;

const PAYABLE_CONTRACT = '0x000000000000000000000000000000000000dead';

function payableAbi(): array
{
    return [['type' => 'function', 'name' => 'deposit', 'inputs' => []]];
}

class ValueRpc implements RpcClient
{
    public array $estimateParams = [];

    public function call(string $method, array $params = []): mixed
    {
        if ($method === 'eth_estimateGas') {
            $this->estimateParams = $params[0];

            return '0x5208';
        }

        return match ($method) {
            'eth_getTransactionCount' => '0x1',
            'eth_gasPrice' => '0x2540be400',
            'eth_maxPriorityFeePerGas' => '0x3b9aca00',
            'eth_getBlockByNumber' => ['baseFeePerGas' => '0x4a817c800'],
            'eth_sendRawTransaction' => '0xvalue',
            'eth_getTransactionReceipt' => ['status' => '0x1'],
            default => [],
        };
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

function valueJob(array $opts): array
{
    $rpc = new ValueRpc;

    new SendTransaction(
        address: PAYABLE_CONTRACT,
        data: '0xd0e30db0',
        opts: array_merge(['timeout' => 5, 'poll_ms' => 1, 'max_replacements' => 0], $opts),
        chainId: 137,
        txCfg: ['estimate_padding' => 1.2, 'confirm_timeout' => 5, 'max_replacements' => 0, 'poll_interval_ms' => 1, 'queue' => 'evm-send'],
    )->handle($rpc, new ReceiptSigner, new ReceiptNonce, new ReceiptFees);

    return [$rpc->estimateParams];
}

// --- the quantity helper -----------------------------------------------------

it('renders a quantity as shortest form hex', function () {
    expect(Encoding::toHexQuantity(0))->toBe('0x0');
    expect(Encoding::toHexQuantity(1))->toBe('0x1');
    expect(Encoding::toHexQuantity('1000000000000000000'))->toBe('0xde0b6b3a7640000');
    expect(Encoding::toHexQuantity('0xde0b6b3a7640000'))->toBe('0xde0b6b3a7640000');
});

it('rejects a negative or oversized amount', function () {
    expect(fn () => Encoding::toHexQuantity('-1'))->toThrow(InvalidArgumentException::class);
    expect(fn () => Encoding::toHexQuantity('not a number'))->toThrow(InvalidArgumentException::class);

    $tooBig = '115792089237316195423570985008687907853269984665640564039457584007913129639936';
    expect(fn () => Encoding::toHexQuantity($tooBig))->toThrow(InvalidArgumentException::class);
});

it('never hands the rlp encoder a bare decimal string', function () {
    // web3p/rlp encodes a non 0x string as its ASCII bytes, so "1000" would
    // become the four characters rather than the number
    expect(Encoding::toHexQuantity('1000'))->toStartWith('0x');
});

// --- the job -----------------------------------------------------------------

it('sends the requested value with a payable call', function () {
    Event::fake();

    valueJob(['value' => '1000000000000000000']);

    Event::assertDispatched(
        TxBroadcasted::class,
        fn (TxBroadcasted $e) => $e->fields['value'] === '0xde0b6b3a7640000'
    );
});

it('keeps value at zero when none was asked for', function () {
    Event::fake();

    valueJob([]);

    Event::assertDispatched(TxBroadcasted::class, fn (TxBroadcasted $e) => $e->fields['value'] === '0x0');
});

it('includes the value in the gas estimate', function () {
    Event::fake();

    [$estimateParams] = valueJob(['value' => '1000000000000000000']);

    expect($estimateParams['value'])->toBe('0xde0b6b3a7640000');
});

it('omits the value from the gas estimate when it is zero', function () {
    Event::fake();

    [$estimateParams] = valueJob([]);

    expect($estimateParams)->not->toHaveKey('value');
});

// --- validation happens at the call site -------------------------------------

it('rejects a bad amount when the job is queued, not inside the worker', function () {
    Queue::fake();
    app()->instance(RpcClient::class, new ValueRpc);
    app()->instance(Signer::class, new ReceiptSigner);

    $contract = Evm::at(PAYABLE_CONTRACT, payableAbi());

    expect(fn () => $contract->sendAsync('deposit', [], ['value' => '-5']))
        ->toThrow(InvalidArgumentException::class);

    Queue::assertNothingPushed();
});

it('normalises the amount before it reaches the queue', function () {
    Queue::fake();
    app()->instance(RpcClient::class, new ValueRpc);
    app()->instance(Signer::class, new ReceiptSigner);

    Evm::at(PAYABLE_CONTRACT, payableAbi())->sendAsync('deposit', [], ['value' => '1000000000000000000']);

    Queue::assertPushed(
        SendTransaction::class,
        fn (SendTransaction $job) => $job->opts['value'] === '0xde0b6b3a7640000'
    );
});
