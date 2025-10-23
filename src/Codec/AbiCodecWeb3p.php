<?php

// src/Codec/AbiCodecWeb3p.php

namespace Farbcode\LaravelEvm\Codec;

use Farbcode\LaravelEvm\Contracts\AbiCodec;
use kornrunner\Keccak;
use Web3\Contract; // keccak256 helper

class AbiCodecWeb3p implements AbiCodec
{
    public function encodeFunction(array|string $abi, string $fn, array $args): string
    {
        // web3p Contract::getData() returns void and fills internal state. To avoid dynamic property reliance
        // implement a lightweight encoder for common static call patterns.
        $abiArray = is_string($abi) ? json_decode($abi, true) : $abi;
        if (! is_array($abiArray)) {
            throw new \InvalidArgumentException('ABI must decode to array');
        }
        $item = null;
        foreach ($abiArray as $entry) {
            if (($entry['type'] ?? '') === 'function' && ($entry['name'] ?? '') === $fn) {
                $item = $entry;
                break;
            }
        }
        if (! $item) {
            throw new \RuntimeException('Function '.$fn.' not found in ABI');
        }
        $inputs = $item['inputs'] ?? [];
        // Build function selector
        $typesSig = implode(',', array_map(fn ($i) => $i['type'], $inputs));
        $signature = $fn.'('.$typesSig.')';
        $hash = Keccak::hash($signature, 256);
        $selector = '0x'.substr($hash, 0, 8);
        // Encode arguments (very simplified: handles address, uint256, bytes32, bool, string)
        $encodedArgs = '';
        foreach ($inputs as $idx => $in) {
            $type = $in['type'];
            $val = $args[$idx] ?? null;
            $encodedArgs .= $this->encodeValue($type, $val);
        }

        return $selector.$encodedArgs;
    }

    private function encodeValue(string $type, mixed $val): string
    {
        // Simplified static encoding (no dynamic types except string truncated)
        if (str_starts_with($type, 'uint')) {
            return str_pad(dechex((int) $val), 64, '0', STR_PAD_LEFT);
        }
        if ($type === 'address') {
            $clean = strtolower(preg_replace('/^0x/', '', (string) $val));

            return str_pad($clean, 64, '0', STR_PAD_LEFT);
        }
        if ($type === 'bytes32') {
            $clean = strtolower(preg_replace('/^0x/', '', (string) $val));

            return str_pad(substr($clean, 0, 64), 64, '0', STR_PAD_RIGHT);
        }
        if ($type === 'bool') {
            return str_pad($val ? '1' : '0', 64, '0', STR_PAD_LEFT);
        }
        if ($type === 'string') {
            // naive: hex of string truncated to 32 bytes
            $hex = bin2hex((string) $val);

            return str_pad(substr($hex, 0, 64), 64, '0', STR_PAD_RIGHT);
        }
        throw new \RuntimeException('Unsupported ABI type '.$type);
    }

    public function callStatic(array|string $abi, string $fn, array $args, callable $ethCall): mixed
    {
        $data = $this->encodeFunction($abi, $fn, $args);

        return $ethCall($data);
    }
}
