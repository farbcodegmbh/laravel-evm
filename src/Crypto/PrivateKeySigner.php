<?php

// src/Crypto/PrivateKeySigner.php

namespace Farbcode\LaravelEvm\Crypto;

use Farbcode\LaravelEvm\Contracts\Signer;
use Farbcode\LaravelEvm\Exceptions\SignerException;
use kornrunner\Ethereum\Address;

class PrivateKeySigner implements Signer
{
    public function __construct(private ?string $privateKey)
    {
        if (! $privateKey || ! preg_match('/^0x[a-fA-F0-9]{64}$/', $privateKey)) {
            throw new SignerException('Invalid private key format');
        }
    }

    public function getAddress(): string
    {
        $address = new Address(ltrim($this->privateKey, '0x'));

        return '0x'.$address->get();
    }

    // expose for internal sign only if needed
    public function privateKey(): string
    {
        return $this->privateKey;
    }
}
