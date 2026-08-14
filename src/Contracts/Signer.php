<?php

// src/Contracts/Signer.php

namespace Farbcode\LaravelEvm\Contracts;

interface Signer
{
    public function getAddress(): string;

    /**
     * Sign an EIP-1559 transaction and return the raw, 0x-prefixed payload
     * ready for eth_sendRawTransaction.
     *
     * Signing belongs to the signer, not to the caller: an implementation
     * backed by a KMS, an HSM or a remote service never has a private key to
     * hand out, and a key that never leaves this object cannot end up in a
     * stack trace.
     *
     * @param  array  $fields  chainId, nonce, maxPriorityFeePerGas, maxFeePerGas,
     *                         gas, to, from, value, data, accessList
     */
    public function sign(array $fields): string;
}
