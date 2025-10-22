<?php
// src/Contracts/AbiCodec.php
namespace Farbcode\LaravelEvm\Contracts;

interface AbiCodec
{
    public function encodeFunction(array|string $abi, string $fn, array $args): string;
    public function callStatic(array|string $abi, string $fn, array $args, callable $ethCall): mixed;
}
