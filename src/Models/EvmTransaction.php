<?php

namespace Farbcode\LaravelEvm\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property string $request_id
 * @property string $signer
 * @property string $to
 * @property string|null $tx_hash
 * @property array $hashes
 * @property string $status
 * @property array|null $receipt
 */
class EvmTransaction extends Model
{
    public const STATUS_QUEUED = 'queued';

    public const STATUS_BROADCAST = 'broadcast';

    public const STATUS_MINED = 'mined';

    public const STATUS_REVERTED = 'reverted';

    public const STATUS_FAILED = 'failed';

    protected $table = 'evm_transactions';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'hashes' => 'array',
            'receipt' => 'array',
            'nonce' => 'integer',
        ];
    }

    public function isSettled(): bool
    {
        return in_array($this->status, [self::STATUS_MINED, self::STATUS_REVERTED, self::STATUS_FAILED], true);
    }
}
