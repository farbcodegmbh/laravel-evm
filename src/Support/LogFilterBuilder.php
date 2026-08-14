<?php

namespace Farbcode\LaravelEvm\Support;

use Farbcode\LaravelEvm\Contracts\RpcClient;
use kornrunner\Keccak;

class LogFilterBuilder
{
    public function __construct(private RpcClient $rpc) {}

    private array $filter = [];

    public static function make(RpcClient $rpc): self
    {
        return new self($rpc);
    }

    public function fromBlock(int|string $block): self
    {
        $this->filter['fromBlock'] = $this->normalizeBlock($block);

        return $this;
    }

    public function toBlock(int|string $block): self
    {
        $this->filter['toBlock'] = $this->normalizeBlock($block);

        return $this;
    }

    public function blockHash(string $hash): self
    {
        $this->filter['blockHash'] = $hash;

        return $this;
    }

    public function address(string|array $address): self
    {
        $this->filter['address'] = $address;

        return $this;
    }

    /**
     * Set single topic value for given index (0-3).
     */
    public function topic(int $index, string $value): self
    {
        $this->ensureTopicIndex($index);
        $this->filter['topics'][$index] = $this->normalizeTopic($value);

        return $this;
    }

    /**
     * Set OR topic values (array of topics) for an index.
     */
    public function topicAny(int $index, array $values): self
    {
        $this->ensureTopicIndex($index);
        $this->filter['topics'][$index] = array_map(fn ($v) => $this->normalizeTopic($v), array_values($values));

        return $this;
    }

    /**
     * Wildcard (null) at index.
     */
    public function topicWildcard(int $index): self
    {
        $this->ensureTopicIndex($index);
        $this->filter['topics'][$index] = null;

        return $this;
    }

    /**
     * Turn the topic slots into the dense, zero-indexed list eth_getLogs expects
     * and drop trailing wildcards.
     *
     * Slots are set by index, so setting only topic2 leaves a sparse array. That
     * has to be filled with explicit nulls first: a sparse array both encodes as
     * a JSON object instead of an array, and would lose entries to the trim
     * below, which walks positions the array does not have.
     */
    private function trimTopics(): void
    {
        if (! isset($this->filter['topics']) || ! is_array($this->filter['topics'])) {
            return;
        }

        $slots = $this->filter['topics'];

        if ($slots === []) {
            unset($this->filter['topics']);

            return;
        }

        $topics = [];
        for ($i = 0; $i <= max(array_keys($slots)); $i++) {
            $topics[$i] = $slots[$i] ?? null;
        }

        while ($topics !== [] && end($topics) === null) {
            array_pop($topics);
        }

        if ($topics === []) {
            unset($this->filter['topics']);

            return;
        }

        $this->filter['topics'] = $topics;
    }

    public function build(): array
    {
        $this->trimTopics();

        return $this->filter;
    }

    public function get(): array
    {
        $f = $this->build();

        return $this->rpc->getLogs($f);
    }

    public function chunked(?int $maxChunk = null): array
    {
        $maxChunk = $maxChunk ?? (int) config('evm.logs.max_chunk', 5000);
        $from = $this->filter['fromBlock'] ?? null;
        $to = $this->filter['toBlock'] ?? null;
        if (! $from || ! $to || $to === 'latest') {
            return $this->get();
        }
        if (! str_starts_with($from, '0x') || ! str_starts_with($to, '0x')) {
            return $this->get();
        }
        $start = hexdec($from);
        $end = hexdec($to);
        if ($end < $start) {
            return [];
        }
        $all = [];
        for ($cursor = $start; $cursor <= $end; $cursor += ($maxChunk + 1)) {
            $chunkEnd = min($end, $cursor + $maxChunk);
            $clone = clone $this;
            $clone->filter['fromBlock'] = '0x'.dechex($cursor);
            $clone->filter['toBlock'] = '0x'.dechex($chunkEnd);
            $all = array_merge($all, $clone->get());
        }

        return $all;
    }

    private function ensureTopicIndex(int $index): void
    {
        if ($index < 0 || $index > 3) {
            throw new \InvalidArgumentException('Topic index must be 0..3');
        }
        if (! isset($this->filter['topics'])) {
            $this->filter['topics'] = [];
        }
    }

    private function normalizeTopic(string $topic): string
    {
        $clean = str_starts_with($topic, '0x') || str_starts_with($topic, '0X')
            ? substr($topic, 2)
            : $topic;

        if (! ctype_xdigit($clean)) {
            throw new \InvalidArgumentException('Topic must be hex, got '.$topic);
        }

        if (strlen($clean) !== 64) {
            throw new \InvalidArgumentException('Topic must be 32 bytes (64 hex chars), got '.strlen($clean));
        }

        return '0x'.strtolower($clean);
    }

    private function normalizeBlock(int|string $block): string
    {
        if (is_string($block) && ($block === 'latest' || $block === 'earliest' || $block === 'pending')) {
            return $block;
        }
        if (is_string($block) && str_starts_with($block, '0x')) {
            return $block;
        }
        if (is_int($block)) {
            return '0x'.dechex($block);
        }
        if (ctype_digit((string) $block)) {
            return '0x'.dechex((int) $block);
        }
        throw new \InvalidArgumentException('Invalid block identifier '.$block);
    }

    /**
     * Set topic0 as keccak256 hash of an event signature string, e.g. Transfer(address,address,uint256)
     */
    public function event(string $signature): self
    {
        $hash = '0x'.Keccak::hash($signature, 256);

        return $this->topic(0, $hash);
    }

    /**
     * Resolve event by name from ABI (array or JSON) and set topic0 accordingly.
     */
    public function eventByAbi(array|string $abi, string $eventName): self
    {
        $abiArr = is_string($abi) ? json_decode($abi, true) : $abi;
        if (! is_array($abiArr)) {
            throw new \InvalidArgumentException('ABI must be array or JSON string');
        }
        foreach ($abiArr as $entry) {
            if (($entry['type'] ?? '') === 'event' && ($entry['name'] ?? '') === $eventName) {
                $inputs = $entry['inputs'] ?? [];
                $types = implode(',', array_map(fn ($in) => $in['type'], $inputs));
                $sig = $eventName.'('.$types.')';

                return $this->event($sig);
            }
        }
        throw new \RuntimeException('Event '.$eventName.' not found in ABI');
    }

    /**
     * Pad an ethereum address (0x...) as 32-byte topic form.
     */
    public static function padAddress(string $address): string
    {
        $clean = strtolower(preg_replace('/^0x/', '', $address));

        return '0x'.str_pad($clean, 64, '0', STR_PAD_LEFT);
    }

    /**
     * Decode a single log entry against an ABI event definition, returning the
     * indexed and non-indexed parameters keyed by name.
     *
     * Supports address, uintN, intN, bool, bytesN and the dynamic types string
     * and bytes. Arrays and tuples are rejected rather than mis-decoded.
     */
    public static function decodeEvent(array|string $abi, array $log): array
    {
        $abiArr = is_string($abi) ? json_decode($abi, true) : $abi;
        if (! is_array($abiArr)) {
            throw new \InvalidArgumentException('ABI must decode to array');
        }

        $topics = $log['topics'] ?? [];
        $dataHex = $log['data'] ?? '0x';

        foreach ($abiArr as $entry) {
            if (($entry['type'] ?? '') !== 'event') {
                continue;
            }

            $inputs = $entry['inputs'] ?? [];
            $typesSig = implode(',', array_map(fn ($i) => $i['type'], $inputs));
            $sig = $entry['name'].'('.$typesSig.')';

            if (($topics[0] ?? '') !== '0x'.Keccak::hash($sig, 256)) {
                continue;
            }

            if ($entry['anonymous'] ?? false) {
                throw new \RuntimeException('Anonymous events are not supported: '.$sig);
            }

            $indexed = [];
            $nonIndexed = [];
            $topicCursor = 1; // topic0 holds the signature hash

            foreach ($inputs as $idx => $in) {
                // Compiler generated ABIs use an empty string, not a missing key
                $name = ($in['name'] ?? '') !== '' ? $in['name'] : 'arg'.$idx;

                if ($in['indexed'] ?? false) {
                    $indexed[$name] = self::decodeTopicValue($in['type'], $topics[$topicCursor] ?? null);
                    $topicCursor++;

                    continue;
                }

                $nonIndexed[] = ['type' => $in['type'], 'name' => $name];
            }

            return array_merge($indexed, self::decodeDataValues($nonIndexed, $dataHex));
        }

        throw new \RuntimeException('No event in the ABI matches topic0 '.($topics[0] ?? '(missing)'));
    }

    /**
     * A dynamic value cannot fit in a topic, so the EVM stores its keccak hash
     * instead. The hash is returned unchanged; the value is not recoverable.
     */
    private static function decodeTopicValue(string $type, ?string $hex): mixed
    {
        if ($hex === null) {
            return null;
        }

        if (self::isDynamicType($type)) {
            return $hex;
        }

        return self::decodeStaticWord($type, strtolower(preg_replace('/^0x/', '', $hex)));
    }

    /**
     * Decode the data segment.
     *
     * The segment starts with one head word per parameter. For a dynamic type
     * that word is a byte offset into the segment, where a length word and the
     * payload follow - decoding the head word in place would yield the offset
     * instead of the value.
     */
    private static function decodeDataValues(array $defs, string $dataHex): array
    {
        $clean = strtolower(preg_replace('/^0x/', '', $dataHex));
        $out = [];

        foreach (array_values($defs) as $position => $def) {
            $head = substr($clean, $position * 64, 64);

            if (! self::isDynamicType($def['type'])) {
                $out[$def['name']] = self::decodeStaticWord($def['type'], $head);

                continue;
            }

            $tail = (int) hexdec($head) * 2;   // byte offset to hex index
            $length = (int) hexdec(substr($clean, $tail, 64)) * 2;
            $payload = substr($clean, $tail + 64, $length);

            $out[$def['name']] = $def['type'] === 'string'
                ? (hex2bin($payload) ?: '')
                : '0x'.$payload;
        }

        return $out;
    }

    private static function isDynamicType(string $type): bool
    {
        return $type === 'string' || $type === 'bytes' || str_ends_with($type, ']');
    }

    private static function decodeStaticWord(string $type, string $word): mixed
    {
        if (str_ends_with($type, ']') || str_starts_with($type, 'tuple')) {
            throw new \RuntimeException('Decoding '.$type.' from an event is not supported');
        }

        return match (true) {
            str_starts_with($type, 'uint') => Encoding::wordToUint($word),
            str_starts_with($type, 'int') => Encoding::wordToInt($word, Encoding::bitsOfType($type)),
            $type === 'address' => '0x'.substr($word, -40),
            $type === 'bool' => ltrim($word, '0') !== '',
            str_starts_with($type, 'bytes') => '0x'.$word,
            default => '0x'.$word,
        };
    }
}
