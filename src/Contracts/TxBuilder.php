<?php
// src/Contracts/TxBuilder.php
namespace Farbcode\LaravelEvm\Contracts;

interface TxBuilder
{
    public function build(array $fields): string;
    public function hashUnsigned(array $fields): string;

}
