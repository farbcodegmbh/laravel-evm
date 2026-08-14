<?php

// src/Crypto/PrivateKeySigner.php

namespace Farbcode\LaravelEvm\Crypto;

use Farbcode\LaravelEvm\Contracts\Signer;
use Farbcode\LaravelEvm\Exceptions\SignerException;
use Farbcode\LaravelEvm\Support\Requirements;
use kornrunner\Ethereum\Address;
use Throwable;
use Web3p\EthereumTx\EIP1559Transaction;

class PrivateKeySigner implements Signer
{
    public function __construct(private ?string $privateKey)
    {
        // kornrunner/ethereum-address calls gmp_init() in its own constructor,
        // so this path needs the extension without ever touching Encoding.
        Requirements::gmp();

        if (! $privateKey || ! preg_match('/^0x[a-fA-F0-9]{64}$/', $privateKey)) {
            throw new SignerException('Invalid private key format');
        }
    }

    public function getAddress(): string
    {
        // substr, not ltrim: ltrim takes a character list, so a key whose first
        // nibble is 0 would lose those digits too and fail the length check.
        $address = new Address(substr($this->privateKey, 2));

        return '0x'.$address->get();
    }

    public function sign(array $fields): string
    {
        try {
            $raw = new EIP1559Transaction($fields)->sign($this->privateKey);
        } catch (Throwable $e) {
            // Never re-throw the original: PHP records argument values in stack
            // traces, and the call that failed received the private key.
            throw new SignerException('Signing the transaction failed: '.$e->getMessage());
        }

        return str_starts_with($raw, '0x') ? $raw : '0x'.$raw;
    }
}
