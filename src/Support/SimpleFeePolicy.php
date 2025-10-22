<?php
// src/Support/SimpleFeePolicy.php
namespace Farbcode\LaravelEvm\Support;

use Farbcode\LaravelEvm\Contracts\FeePolicy;

class SimpleFeePolicy implements FeePolicy
{
    public function __construct(private array $cfg) {}

    public function suggest(callable $gasPriceFetcher): array
    {
        $hex = $gasPriceFetcher();
        $base = is_string($hex) ? hexdec($hex) : (int) $hex;

        $minPrio = (int) ($this->cfg['min_priority_gwei'] ?? 3) * 1_000_000_000;
        $minMax  = (int) ($this->cfg['min_maxfee_gwei'] ?? 40) * 1_000_000_000;
        $mult    = (float) ($this->cfg['base_multiplier'] ?? 3);

        $priority = max($minPrio, (int)($base * 0.1));
        $maxFee   = max($minMax,  (int)($base * $mult));
        return [$priority, $maxFee];
    }

    public function replace(int $oldPriority, int $oldMax): array
    {
        $factor = (float) ($this->cfg['replacement_factor'] ?? 1.5);
        $priority = max((int)($oldPriority * $factor), $oldPriority + 1_000_000_000);
        $max      = max((int)($oldMax * $factor), $oldMax + 10_000_000_000);
        return [$priority, $max];
    }
}
