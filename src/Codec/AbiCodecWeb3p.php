<?php
// src/Codec/AbiCodecWeb3p.php
namespace Farbcode\LaravelEvm\Codec;

use Farbcode\LaravelEvm\Contracts\AbiCodec;
use Web3\Contract;

class AbiCodecWeb3p implements AbiCodec
{
    public function encodeFunction(array|string $abi, string $fn, array $args): string
    {
        $c = new Contract('', is_array($abi) ? json_encode($abi) : $abi);
        return $c->getData($fn, ...$args);
    }

    public function callStatic(array|string $abi, string $fn, array $args, callable $ethCall): mixed
    {
        $data = $this->encodeFunction($abi, $fn, $args);
        return $ethCall($data);
    }
}
