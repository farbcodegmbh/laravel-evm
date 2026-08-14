<?php

namespace Farbcode\LaravelEvm\Support;

/**
 * Lightweight wrapper for raw eth_call hex results with simple ABI decoding helpers.
 * Allows: $contract->call('fn')->as('string'). Backwards compatible via __toString() returning raw hex.
 */
class CallResult
{
    public function __construct(private string $rawHex) {}

    public function raw(): string
    {
        return $this->rawHex;
    }

    public function __toString(): string
    {
        return $this->rawHex;
    }

    /**
     * Decode the result to a basic ABI type: string, bytes, uintN, intN, bool, address.
     *
     * Integers are returned as decimal strings so that values beyond PHP's
     * integer range survive intact.
     */
    public function as(string $type): mixed
    {
        $type = strtolower(trim($type));

        if (str_starts_with($type, 'uint')) {
            Encoding::bitsOfType($type);

            return Encoding::wordToUint($this->lastWord());
        }

        if (str_starts_with($type, 'int')) {
            return Encoding::wordToInt($this->lastWord(), Encoding::bitsOfType($type));
        }

        return match ($type) {
            'string' => $this->decodeDynamicString(),
            'bytes' => $this->decodeDynamicString(),
            'bool' => $this->decodeBool(),
            'address' => $this->decodeAddress(),
            default => throw new \InvalidArgumentException('Unsupported type for CallResult::as(): '.$type),
        };
    }

    private function strip(string $hex): string
    {
        return str_starts_with($hex, '0x') ? substr($hex, 2) : $hex;
    }

    private function decodeDynamicString(): string
    {
        $hex = $this->strip($this->rawHex);
        if ($hex === '' || $hex === '0') {
            return '';
        }

        // Dynamic string layout (single return value): [offset(32B)][...][length(32B)][data(length B, padded)]
        if (strlen($hex) < 128) { // treat as statically padded string fallback
            $bin = hex2bin($hex);

            return $bin === false ? '' : rtrim($bin, "\0");
        }

        $offset = hexdec(substr($hex, 0, 64));
        $lenPos = $offset * 2; // convert byte offset to hex string index
        if ($lenPos + 64 > strlen($hex)) {
            return '';
        }
        $length = hexdec(substr($hex, $lenPos, 64));
        $dataPos = $lenPos + 64;
        $dataHex = substr($hex, $dataPos, $length * 2);
        $bin = hex2bin($dataHex);

        return $bin === false ? '' : $bin;
    }

    private function lastWord(): string
    {
        return substr($this->strip($this->rawHex), -64);
    }

    private function decodeBool(): bool
    {
        return ltrim($this->lastWord(), '0') !== '';
    }

    private function decodeAddress(): string
    {
        return '0x'.substr($this->lastWord(), -40);
    }
}
