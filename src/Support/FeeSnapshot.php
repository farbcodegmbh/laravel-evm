<?php

namespace Farbcode\LaravelEvm\Support;

use Farbcode\LaravelEvm\Contracts\RpcClient;
use Throwable;

/**
 * What the chain currently charges, as decimal wei strings.
 *
 * EIP-1559 splits the price into a base fee that the protocol sets per block
 * and burns, and a priority fee that goes to the validator. eth_gasPrice
 * collapses both into one legacy number, so deriving a tip from it - the
 * package used to take 10% of it - is not a tip estimate at all.
 *
 * Every field is nullable because not every node answers every method: older
 * or trimmed-down RPC providers may not implement eth_maxPriorityFeePerGas,
 * and pre-1559 chains have no base fee.
 */
final class FeeSnapshot
{
    public function __construct(
        public readonly ?string $baseFeePerGas = null,
        public readonly ?string $priorityFeePerGas = null,
        public readonly ?string $gasPrice = null,
    ) {}

    public static function fromRpc(RpcClient $rpc): self
    {
        return new self(
            self::tryFetch(fn () => self::wei($rpc->call('eth_getBlockByNumber', ['latest', false])['baseFeePerGas'] ?? null)),
            self::tryFetch(fn () => self::wei($rpc->call('eth_maxPriorityFeePerGas'))),
            self::tryFetch(fn () => self::wei($rpc->call('eth_gasPrice'))),
        );
    }

    /**
     * A missing method must not take the transaction down; the policy decides
     * what to do with the gap.
     */
    private static function tryFetch(callable $fetch): ?string
    {
        try {
            return $fetch();
        } catch (Throwable) {
            return null;
        }
    }

    private static function wei(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return is_string($value) ? Encoding::wordToUint($value) : (string) (int) $value;
    }
}
