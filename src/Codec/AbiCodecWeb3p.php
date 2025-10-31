<?php

// src/Codec/AbiCodecWeb3p.php

namespace Farbcode\LaravelEvm\Codec;

use Farbcode\LaravelEvm\Contracts\AbiCodec;
use kornrunner\Keccak;

class AbiCodecWeb3p implements AbiCodec
{
    public function encodeFunction(array|string $abi, string $fn, array $args): string
    {
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

        $typesSig = implode(',', array_map(fn ($i) => $i['type'], $inputs));
        $signature = $fn.'('.$typesSig.')';
        $hash = Keccak::hash($signature, 256);
        $selector = '0x'.substr($hash, 0, 8);

        // Build head (static slots + dynamic offsets) and tail (dynamic data)
        $head = [];
        $dynamicParts = [];
        foreach ($inputs as $idx => $in) {
            $type = $in['type'];
            $val = $args[$idx] ?? null;
            if ($this->isDynamic($type)) {
                // Placeholder offset, will fill after we know tail sizes
                $head[] = '__DYNAMIC_OFFSET_PLACEHOLDER__';
                $dynamicParts[] = $this->encodeDynamic($type, $val);
            } else {
                $head[] = $this->encodeStatic($type, $val);
            }
        }

        // Compute offsets (in bytes) for dynamic parts
        $baseHeadSize = 32 * count($head); // bytes
        $tailSoFar = 0;
        $dynamicIndex = 0;
        foreach ($head as $i => $slot) {
            if ($slot === '__DYNAMIC_OFFSET_PLACEHOLDER__') {
                $offset = $baseHeadSize + $tailSoFar; // bytes from start of args (after selector)
                $head[$i] = str_pad(dechex($offset), 64, '0', STR_PAD_LEFT);
                $tailSoFar += strlen($dynamicParts[$dynamicIndex]) / 2; // hex length /2 = bytes
                $dynamicIndex++;
            }
        }

        $encodedHead = implode('', $head);
        $encodedTail = implode('', $dynamicParts);

        return $selector.$encodedHead.$encodedTail;
    }

    private function isDynamic(string $type): bool
    {
        return in_array($type, ['string', 'bytes']) || $type === 'bytes' || str_starts_with($type, 'bytes') === false && in_array($type, ['string', 'bytes']);
    }

    private function encodeStatic(string $type, mixed $val): string
    {
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
        // Fallback for unsupported static types
        throw new \RuntimeException('Unsupported static ABI type '.$type);
    }

    private function encodeDynamic(string $type, mixed $val): string
    {
        // Only string/bytes handled
        if ($type === 'string') {
            $bin = (string) $val;
            $hex = bin2hex($bin);
            $length = strlen($bin); // bytes
            $lenSlot = str_pad(dechex($length), 64, '0', STR_PAD_LEFT);
            $dataPadded = $this->padHexRight($hex);
            return $lenSlot.$dataPadded;
        }
        if ($type === 'bytes') {
            $clean = preg_replace('/^0x/', '', (string) $val);
            $bin = hex2bin($clean) ?: '';
            $length = strlen($bin);
            $lenSlot = str_pad(dechex($length), 64, '0', STR_PAD_LEFT);
            $dataPadded = $this->padHexRight($clean);
            return $lenSlot.$dataPadded;
        }
        throw new \RuntimeException('Unsupported dynamic ABI type '.$type);
    }

    private function padHexRight(string $hex): string
    {
        $bytesLen = (int) ceil(strlen($hex) / 2);
        $padBytes = (32 - ($bytesLen % 32)) % 32;
        return $hex.str_repeat('00', $padBytes);
    }

    public function callStatic(array|string $abi, string $fn, array $args, callable $ethCall): mixed
    {
        $data = $this->encodeFunction($abi, $fn, $args);
        return $ethCall($data);
    }
}
