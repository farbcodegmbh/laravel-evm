<?php

// src/Codec/AbiCodecWeb3p.php

namespace Farbcode\LaravelEvm\Codec;

use Farbcode\LaravelEvm\Contracts\AbiCodec;
use Farbcode\LaravelEvm\Support\Encoding;
use kornrunner\Keccak;

class AbiCodecWeb3p implements AbiCodec
{
    /**
     * Encode a call.
     *
     * $fn is either a function name, or a full signature such as
     * "safeTransferFrom(address,address,uint256)" when overloads have to be
     * told apart.
     */
    public function encodeFunction(array|string $abi, string $fn, array $args): string
    {
        $abiArray = is_string($abi) ? json_decode($abi, true) : $abi;
        if (! is_array($abiArray)) {
            throw new \InvalidArgumentException('ABI must decode to array');
        }

        $inputs = $this->resolveInputs($abiArray, $fn, $args);

        if (count($args) !== count($inputs)) {
            throw new \InvalidArgumentException(sprintf(
                '%s expects %d argument(s), %d given',
                $fn,
                count($inputs),
                count($args)
            ));
        }

        $typesSig = implode(',', array_map(fn ($i) => $i['type'], $inputs));
        $signature = $this->baseName($fn).'('.$typesSig.')';
        $selector = '0x'.substr(Keccak::hash($signature, 256), 0, 8);

        // Build head (static slots + dynamic offsets) and tail (dynamic data)
        $head = [];
        $dynamicParts = [];
        foreach ($inputs as $idx => $in) {
            $type = $in['type'];
            $val = $args[$idx];
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
                $tailSoFar += (int) (strlen($dynamicParts[$dynamicIndex]) / 2); // hex length /2 = bytes
                $dynamicIndex++;
            }
        }

        return $selector.implode('', $head).implode('', $dynamicParts);
    }

    /**
     * Pick the ABI entry for $fn.
     *
     * Solidity allows overloads, so matching by name alone is ambiguous:
     * stopping at the first hit makes every later overload unreachable and can
     * encode a selector the contract does not have.
     *
     * @return list<array{type: string, name?: string}>
     */
    private function resolveInputs(array $abi, string $fn, array $args): array
    {
        $wantsSignature = str_contains($fn, '(');
        $name = $this->baseName($fn);

        $candidates = [];
        foreach ($abi as $entry) {
            if (($entry['type'] ?? '') !== 'function' || ($entry['name'] ?? '') !== $name) {
                continue;
            }

            $inputs = array_values($entry['inputs'] ?? []);
            $signature = $name.'('.implode(',', array_map(fn ($i) => $i['type'], $inputs)).')';

            if ($wantsSignature) {
                if ($signature === $fn) {
                    return $inputs;
                }

                continue;
            }

            $candidates[$signature] = $inputs;
        }

        if ($candidates === []) {
            throw new \RuntimeException(($wantsSignature ? 'Signature ' : 'Function ').$fn.' not found in ABI');
        }

        if (count($candidates) === 1) {
            return reset($candidates);
        }

        $byArity = array_filter($candidates, fn ($inputs) => count($inputs) === count($args));

        if (count($byArity) === 1) {
            return reset($byArity);
        }

        throw new \RuntimeException(sprintf(
            '%s is overloaded in the ABI (%s). Pass the full signature to choose one.',
            $name,
            implode(', ', array_keys($candidates))
        ));
    }

    private function baseName(string $fn): string
    {
        $paren = strpos($fn, '(');

        return $paren === false ? $fn : substr($fn, 0, $paren);
    }

    private function isDynamic(string $type): bool
    {
        return $type === 'string' || $type === 'bytes' || str_ends_with($type, ']');
    }

    private function encodeStatic(string $type, mixed $val): string
    {
        if ($this->isUnsupported($type)) {
            throw new \RuntimeException('Unsupported ABI type '.$type);
        }

        if (str_starts_with($type, 'uint')) {
            return Encoding::uintToWord($this->requireNumeric($type, $val), Encoding::bitsOfType($type));
        }

        if (str_starts_with($type, 'int')) {
            return Encoding::intToWord($this->requireNumeric($type, $val), Encoding::bitsOfType($type));
        }

        if ($type === 'address') {
            $clean = $this->requireHex($type, $val);

            if (strlen($clean) !== 40) {
                throw new \InvalidArgumentException('Address must be 20 bytes (40 hex chars), got '.strlen($clean));
            }

            return str_pad($clean, 64, '0', STR_PAD_LEFT);
        }

        if (str_starts_with($type, 'bytes')) {
            $size = (int) substr($type, 5);

            if ($size < 1 || $size > 32) {
                throw new \RuntimeException('Unsupported ABI type '.$type);
            }

            $clean = $this->requireHex($type, $val);

            if (strlen($clean) !== $size * 2) {
                throw new \InvalidArgumentException(sprintf('%s must be %d hex chars, got %d', $type, $size * 2, strlen($clean)));
            }

            // bytesN is right aligned in its word
            return str_pad($clean, 64, '0', STR_PAD_RIGHT);
        }

        if ($type === 'bool') {
            if (! is_bool($val) && ! in_array($val, [0, 1, '0', '1'], true)) {
                throw new \InvalidArgumentException('Argument of type bool must be a boolean');
            }

            return str_pad($val ? '1' : '0', 64, '0', STR_PAD_LEFT);
        }

        throw new \RuntimeException('Unsupported static ABI type '.$type);
    }

    private function encodeDynamic(string $type, mixed $val): string
    {
        if ($type === 'string') {
            if (! is_string($val)) {
                throw new \InvalidArgumentException('Argument of type string must be a string');
            }

            $lenSlot = str_pad(dechex(strlen($val)), 64, '0', STR_PAD_LEFT);

            return $lenSlot.$this->padHexRight(bin2hex($val));
        }

        if ($type === 'bytes') {
            $clean = $this->requireHex($type, $val);

            if (strlen($clean) % 2 !== 0) {
                throw new \InvalidArgumentException('bytes must have an even number of hex chars, got '.strlen($clean));
            }

            $lenSlot = str_pad(dechex((int) (strlen($clean) / 2)), 64, '0', STR_PAD_LEFT);

            return $lenSlot.$this->padHexRight($clean);
        }

        throw new \RuntimeException('Unsupported dynamic ABI type '.$type);
    }

    /**
     * Arrays and tuples need a real recursive encoder. Until there is one they
     * have to fail loudly - `uint256[]` in particular used to reach the uint
     * branch and encode the array as the number 1.
     */
    private function isUnsupported(string $type): bool
    {
        return str_ends_with($type, ']') || str_starts_with($type, 'tuple');
    }

    private function requireNumeric(string $type, mixed $val): int|string
    {
        if (! is_int($val) && ! is_string($val)) {
            throw new \InvalidArgumentException('Argument of type '.$type.' must be an int, a decimal string or 0x-prefixed hex');
        }

        return $val;
    }

    private function requireHex(string $type, mixed $val): string
    {
        if (! is_string($val)) {
            throw new \InvalidArgumentException('Argument of type '.$type.' must be a hex string');
        }

        $clean = strtolower(preg_replace('/^0[xX]/', '', $val));

        if ($clean !== '' && ! ctype_xdigit($clean)) {
            throw new \InvalidArgumentException('Argument of type '.$type.' must be hex, got '.$val);
        }

        return $clean;
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
