<?php

// src/Support/SimpleFeePolicy.php

namespace Farbcode\LaravelEvm\Support;

use Farbcode\LaravelEvm\Contracts\FeePolicy;

/**
 * maxFeePerGas = baseFee * multiplier + tip, with the configured gwei values
 * acting as floors rather than as the primary source.
 *
 * The multiplier is headroom for the base fee rising while the transaction
 * waits: it can grow by at most 12.5% per block, so a multiplier of 2 covers
 * roughly six blocks. Anything above the actual base fee at inclusion time is
 * refunded, so headroom costs nothing but the balance it reserves.
 */
class SimpleFeePolicy implements FeePolicy
{
    private const WEI_PER_GWEI = 1_000_000_000;

    /**
     * geth and erigon require a 10% bump on both caps; 12.5% leaves room for
     * rounding without overpaying.
     */
    private const MIN_REPLACEMENT_FACTOR = 1.125;

    public function __construct(private array $cfg) {}

    public function suggest(FeeSnapshot $snapshot): array
    {
        $gasPrice = $this->toInt($snapshot->gasPrice);
        $baseFee = $this->toInt($snapshot->baseFeePerGas);

        // Prefer what the node reports as a tip. Falling back to a share of
        // eth_gasPrice is a guess, and only reasonable when nothing better exists.
        $tip = $snapshot->priorityFeePerGas !== null
            ? $this->toInt($snapshot->priorityFeePerGas)
            : (int) ($gasPrice * 0.1);

        $tip = max($this->floor('min_priority_gwei', 1), $tip);

        $multiplier = (float) ($this->cfg['base_multiplier'] ?? 2);

        $maxFee = $baseFee > 0
            ? (int) ($baseFee * $multiplier) + $tip
            : (int) ($gasPrice * $multiplier);

        $maxFee = max($this->floor('min_maxfee_gwei', 0), $maxFee);

        // A cap below the tip is rejected outright by the node.
        return [$tip, max($maxFee, $tip)];
    }

    public function replace(int $oldPriority, int $oldMax): array
    {
        $factor = max(self::MIN_REPLACEMENT_FACTOR, (float) ($this->cfg['replacement_factor'] ?? 1.5));

        // The absolute floors keep the bump meaningful when the old caps are
        // small enough that a percentage rounds down to nothing.
        $priority = max((int) ceil($oldPriority * $factor), $oldPriority + self::WEI_PER_GWEI);
        $maxFee = max((int) ceil($oldMax * $factor), $oldMax + self::WEI_PER_GWEI);

        return [$priority, max($maxFee, $priority)];
    }

    private function floor(string $key, int $default): int
    {
        return (int) ($this->cfg[$key] ?? $default) * self::WEI_PER_GWEI;
    }

    private function toInt(?string $wei): int
    {
        return $wei === null ? 0 : (int) $wei;
    }
}
