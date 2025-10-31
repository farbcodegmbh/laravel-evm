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
     * Remove trailing null topic slots for clean filter.
     */
    private function trimTopics(): void
    {
        if (! isset($this->filter['topics']) || ! is_array($this->filter['topics'])) {
            return;
        }
        $topics = $this->filter['topics'];
        for ($i = count($topics) - 1; $i >= 0; $i--) {
            if ($topics[$i] === null) {
                array_pop($topics);
            } else {
                break;
            }
        }
        if (empty($topics)) {
            unset($this->filter['topics']);
        } else {
            $this->filter['topics'] = $topics;
        }
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
        return str_starts_with($topic, '0x') ? $topic : '0x'.ltrim($topic, '0x');
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
     * Decode a single log entry using ABI event definition (returns associative array of indexed/non-indexed values).
     * Simplified: supports address, uint256, bool, bytes32, string (string only when not indexed, from data segment).
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
            if (($entry['type'] ?? '') === 'event') {
                $inputs = $entry['inputs'] ?? [];
                $typesSig = implode(',', array_map(fn ($i) => $i['type'], $inputs));
                $sig = $entry['name'].'('.$typesSig.')';
                $expected = '0x'.Keccak::hash($sig, 256);
                if (($topics[0] ?? '') !== $expected) {
                    continue;
                }
                $indexedDecoded = [];
                $nonIndexedTypes = [];
                foreach ($inputs as $idx => $in) {
                    $type = $in['type'];
                    $name = $in['name'] ?? 'arg'.$idx;
                    if ($in['indexed'] ?? false) {
                        $raw = $topics[count($indexedDecoded) + 1] ?? null; // topic0 is signature
                        $indexedDecoded[$name] = self::decodeTopicValue($type, $raw);
                    } else {
                        $nonIndexedTypes[] = ['type' => $type, 'name' => $name];
                    }
                }
                $nonIndexedDecoded = self::decodeDataValues($nonIndexedTypes, $dataHex);

                return array_merge($indexedDecoded, $nonIndexedDecoded);
            }
        }

        return [];
    }

    private static function decodeTopicValue(string $type, ?string $hex): mixed
    {
        if ($hex === null) {
            return null;
        }
        $clean = strtolower(preg_replace('/^0x/', '', $hex));

        return match (true) {
            str_starts_with($type, 'uint') => hexdec($clean),
            $type === 'address' => '0x'.substr($clean, -40),
            $type === 'bool' => (bool) hexdec(substr($clean, -1)),
            $type === 'bytes32' => '0x'.$clean,
            default => $hex,
        };
    }

    private static function decodeDataValues(array $defs, string $dataHex): array
    {
        $out = [];
        $clean = strtolower(preg_replace('/^0x/', '', $dataHex));
        $offset = 0;
        foreach ($defs as $def) {
            $chunk = substr($clean, $offset, 64);
            $offset += 64;
            $type = $def['type'];
            $name = $def['name'];
            $out[$name] = match (true) {
                str_starts_with($type, 'uint') => hexdec($chunk),
                $type === 'address' => '0x'.substr($chunk, -40),
                $type === 'bool' => (bool) hexdec(substr($chunk, -1)),
                $type === 'bytes32' => '0x'.$chunk,
                $type === 'string' => trim(@hex2bin($chunk) ?: ''),
                default => '0x'.$chunk,
            };
        }

        return $out;
    }
}
