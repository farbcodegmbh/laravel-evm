<?php

// src/Contracts/Signer.php

namespace Farbcode\LaravelEvm\Contracts;

interface Signer
{
    public function getAddress(): string;
}
