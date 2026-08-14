<?php

namespace Farbcode\LaravelEvm\Support;

use kornrunner\Keccak;

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

    /**
     * Render a non-negative integer as an RLP quantity: 0x plus the shortest
     * hex representation, no leading zeros.
     *
     * Transaction fields must not be handed to the RLP encoder as bare decimal
     * strings. web3p/rlp routes anything without a 0x prefix through its string
     * encoder, so "1000" would be encoded as the four ASCII characters rather
     * than as the number.
     */
    public static function toHexQuantity(int|string $value): string
    {
        $number = self::toGmp($value);

        if (gmp_sign($number) < 0) {
            throw new \InvalidArgumentException('Quantity must not be negative, got: '.$value);
        }

        if (gmp_cmp($number, gmp_sub(self::twoPow(256), 1)) > 0) {
            throw new \InvalidArgumentException('Quantity exceeds the range of uint256');
        }

        return '0x'.gmp_strval($number, 16);
    }

    /**
     * Validate an address and return it lowercased with a 0x prefix.
     */
    public static function normalizeAddress(string $address): string
    {
        $clean = strtolower(preg_replace('/^0[xX]/', '', trim($address)));

        if (strlen($clean) !== 40 || ! ctype_xdigit($clean)) {
            throw new \InvalidArgumentException('Address must be 20 bytes (40 hex chars), got: '.$address);
        }

        return '0x'.$clean;
    }

    /**
     * Apply the EIP-55 checksum to an address: the lowercase hex without the 0x
     * prefix is hashed, then each digit is upcased where the matching hash
     * nibble is >= 8.
     */
    public static function toChecksumAddress(string $address): string
    {
        // substr, not ltrim: ltrim takes a character list, so an address whose
        // first nibble is 0 would lose those digits and come out truncated.
        $hex = strtolower(str_starts_with(strtolower($address), '0x') ? substr($address, 2) : $address);

        if (strlen($hex) !== 40 || ! ctype_xdigit($hex)) {
            throw new \InvalidArgumentException('Address must be 20 bytes (40 hex chars), got: '.$address);
        }

        $hash = Keccak::hash($hex, 256);
        $out = '0x';
        for ($i = 0; $i < 40; $i++) {
            $out .= hexdec($hash[$i]) >= 8 ? strtoupper($hex[$i]) : $hex[$i];
        }

        return $out;
    }

    /**
     * Bit width of a uint/int ABI type. `uint` and `int` are aliases for 256 bits.
     */
    public static function bitsOfType(string $type): int
    {
        $suffix = str_starts_with($type, 'uint')
            ? substr($type, 4)
            : (str_starts_with($type, 'int') ? substr($type, 3) : null);

        if ($suffix === null) {
            throw new \InvalidArgumentException('Not an integer ABI type: '.$type);
        }

        if ($suffix === '') {
            return 256;
        }

        if (! ctype_digit($suffix)) {
            throw new \InvalidArgumentException('Invalid integer ABI type: '.$type);
        }

        $bits = (int) $suffix;
        if ($bits < 8 || $bits > 256 || $bits % 8 !== 0) {
            throw new \InvalidArgumentException('Invalid integer width in ABI type: '.$type);
        }

        return $bits;
    }

    /**
     * Encode an unsigned integer as a 32-byte ABI word (64 hex chars, no 0x prefix).
     * Accepts an int, a decimal string or a 0x-prefixed hex string; rejects
     * anything outside the range of the given bit width.
     */
    public static function uintToWord(int|string $value, int $bits = 256): string
    {
        $number = self::toGmp($value);

        if (gmp_sign($number) < 0) {
            throw new \InvalidArgumentException('Value must not be negative for uint'.$bits);
        }

        if (gmp_cmp($number, gmp_sub(self::twoPow($bits), 1)) > 0) {
            throw new \InvalidArgumentException('Value exceeds the range of uint'.$bits);
        }

        return self::padWord(gmp_strval($number, 16));
    }

    /**
     * Encode a signed integer as a 32-byte ABI word using two's complement.
     */
    public static function intToWord(int|string $value, int $bits = 256): string
    {
        $number = self::toGmp($value);

        if (gmp_cmp($number, gmp_neg(self::twoPow($bits - 1))) < 0
            || gmp_cmp($number, gmp_sub(self::twoPow($bits - 1), 1)) > 0) {
            throw new \InvalidArgumentException('Value exceeds the range of int'.$bits);
        }

        if (gmp_sign($number) < 0) {
            $number = gmp_add(self::twoPow(256), $number);
        }

        return self::padWord(gmp_strval($number, 16));
    }

    /**
     * Decode a hex word into an unsigned decimal string. Always returns a string
     * so that values beyond PHP's integer range survive intact.
     */
    public static function wordToUint(string $hex): string
    {
        return gmp_strval(self::hexToGmp($hex), 10);
    }

    /**
     * Decode a hex word into a signed decimal string, resolving two's complement.
     */
    public static function wordToInt(string $hex, int $bits = 256): string
    {
        $number = self::hexToGmp($hex);

        if (gmp_testbit($number, $bits - 1)) {
            $number = gmp_sub($number, self::twoPow($bits));
        }

        return gmp_strval($number, 10);
    }

    private static function toGmp(int|string $value): \GMP
    {
        if (is_int($value)) {
            return gmp_init($value, 10);
        }

        $trimmed = trim($value);

        if (preg_match('/^-?[0-9]+$/', $trimmed)) {
            return gmp_init($trimmed, 10);
        }

        if (preg_match('/^0[xX][0-9a-fA-F]+$/', $trimmed)) {
            return gmp_init(substr($trimmed, 2), 16);
        }

        throw new \InvalidArgumentException('Expected an integer, a decimal string or 0x-prefixed hex, got: '.$value);
    }

    private static function hexToGmp(string $hex): \GMP
    {
        $clean = preg_replace('/^0[xX]/', '', trim($hex));

        if ($clean === '') {
            return gmp_init(0, 10);
        }

        if (! ctype_xdigit($clean)) {
            throw new \InvalidArgumentException('Expected hex, got: '.$hex);
        }

        return gmp_init($clean, 16);
    }

    /**
     * gmp_pow() refuses exponents this large, so set the bit directly.
     */
    private static function twoPow(int $exponent): \GMP
    {
        $number = gmp_init(0);
        gmp_setbit($number, $exponent);

        return $number;
    }

    private static function padWord(string $hex): string
    {
        if (strlen($hex) > 64) {
            throw new \InvalidArgumentException('Encoded value does not fit into a 32-byte word');
        }

        return str_pad($hex, 64, '0', STR_PAD_LEFT);
    }
}
