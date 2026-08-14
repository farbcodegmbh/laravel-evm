<?php

namespace Farbcode\LaravelEvm\Support;

/**
 * Helpers for reading an eth_getTransactionReceipt result.
 *
 * A receipt only proves that the transaction was included in a block. Whether
 * the contract call actually succeeded is carried by the `status` field:
 * 0x1 on success, 0x0 when the transaction reverted.
 */
class Receipt
{
    /**
     * Whether the transaction was included and did not revert.
     *
     * Receipts from before EIP-658 carry no status field. Those are reported as
     * successful, which matches how other EVM clients treat them.
     */
    public static function isSuccessful(?array $receipt): bool
    {
        return $receipt !== null && self::status($receipt) !== 0;
    }

    /**
     * Whether the transaction was included but reverted.
     */
    public static function isReverted(?array $receipt): bool
    {
        return $receipt !== null && self::status($receipt) === 0;
    }

    /**
     * Numeric status of the receipt, or null when the field is absent.
     */
    public static function status(array $receipt): ?int
    {
        if (! isset($receipt['status'])) {
            return null;
        }

        $status = $receipt['status'];

        if (is_int($status)) {
            return $status;
        }

        if (! is_string($status)) {
            return null;
        }

        $clean = preg_replace('/^0[xX]/', '', trim($status));

        return $clean === '' || ! ctype_xdigit($clean) ? null : (int) hexdec($clean);
    }
}
