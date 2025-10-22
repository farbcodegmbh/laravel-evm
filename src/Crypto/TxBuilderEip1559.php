<?php

namespace Farbcode\LaravelEvm\Crypto;

use Farbcode\LaravelEvm\Contracts\TxBuilder;
use Web3p\EthereumTx\EIP1559Transaction;

class TxBuilderEip1559 implements TxBuilder
{
    /**
     * Build unsigned serialized payload.
     * Note: usually you do not need this, use sign() directly in the job.
     */
    public function build(array $fields): string
    {
        $tx = new EIP1559Transaction($fields);

        return $tx->serialize(); // unsigned RLP
    }

    /**
     * Hash of the unsigned transaction (if the lib supports it).
     * Some versions expose hash(false). If not available, throw.
     */
    public function hashUnsigned(array $fields): string
    {
        $tx = new EIP1559Transaction($fields);
        if (method_exists($tx, 'hash')) {
            return $tx->hash(false);
        }
        throw new \BadMethodCallException('Unsigned hash not supported by this EIP1559Transaction version');
    }

    /**
     * Helper you may call from your code if you want signing here instead of the Job.
     */
    public function sign(array $fields, string $privateKey): string
    {
        return new EIP1559Transaction($fields)->sign($privateKey);
    }
}
