<?php

namespace Farbcode\LaravelEvm\Support;

class Encoding
{
    /**
     * Convert an ASCII/UTF-8 string into a bytes32 ABI value.
     * - Returns 0x-prefixed hex (64 hex chars payload).
     * - Right pads with zeros if shorter than 32 bytes.
     * - If longer than 32 bytes, either truncate (default) or throw.
     */
    public static function stringToBytes32(string $input, bool $truncate = true): string
    {
        $bytes = mb_convert_encoding($input, 'UTF-8');
        $bin = $bytes; // already UTF-8
        $len = strlen($bin);
        if ($len > 32 && ! $truncate) {
            throw new \InvalidArgumentException('Input exceeds 32 bytes and truncate disabled');
        }
        if ($len > 32) {
            $bin = substr($bin, 0, 32);
        }
        $hex = bin2hex($bin);
        $hex = str_pad($hex, 64, '0');

        return '0x'.$hex;
    }

    /**
     * Decode bytes32 hex (0x...) back to string trimming trailing null bytes.
     */
    public static function bytes32ToString(string $hex): string
    {
        $clean = strtolower(preg_replace('/^0x/', '', $hex));
        if (strlen($clean) !== 64) {
            throw new \InvalidArgumentException('Hex must be 32 bytes (64 hex chars)');
        }
        $bin = hex2bin($clean) ?: '';

        return rtrim($bin, "\x00");
    }
}
