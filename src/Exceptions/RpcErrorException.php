<?php

namespace Farbcode\LaravelEvm\Exceptions;

/**
 * The node answered, and the answer was a JSON-RPC error.
 *
 * This is a verdict about the request, not about the endpoint: a revert,
 * insufficient funds or a nonce that is too low will come back identically from
 * every provider, so it must not be retried elsewhere. The `data` field carries
 * the ABI encoded revert reason or custom error selector where the node
 * supplies one.
 */
class RpcErrorException extends RpcException
{
    public function __construct(
        string $message,
        public readonly ?int $rpcCode = null,
        public readonly mixed $rpcData = null,
    ) {
        parent::__construct($message);
    }

    /**
     * Solidity reverts surface as code 3 on most nodes; some use -32000 with a
     * message naming the revert.
     */
    public function isRevert(): bool
    {
        return $this->rpcCode === 3
            || str_contains(strtolower($this->getMessage()), 'execution reverted');
    }
}
