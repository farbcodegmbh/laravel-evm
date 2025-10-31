<?php

namespace Workbench\App\Providers;

use Farbcode\LaravelEvm\Events\CallPerformed;
use Farbcode\LaravelEvm\Events\TxBroadcasted;
use Farbcode\LaravelEvm\Events\TxFailed;
use Farbcode\LaravelEvm\Events\TxMined;
use Farbcode\LaravelEvm\Events\TxQueued;
use Farbcode\LaravelEvm\Events\TxReplaced;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;

class WorkbenchServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        // Log read calls
        Event::listen(CallPerformed::class, function (CallPerformed $e) {
            Log::info('EVM CallPerformed', [
                'address' => $e->address,
                'function' => $e->function,
                'args' => $e->args,
                'rawResult' => $e->rawResult,
            ]);
        });

        // Log when transaction is queued
        Event::listen(TxQueued::class, function (TxQueued $e) {
            Log::info('EVM TxQueued', [
                'to' => $e->to,
                'data' => $e->data,
            ]);
        });

        // Log when transaction broadcasted
        Event::listen(TxBroadcasted::class, function (TxBroadcasted $e) {
            Log::info('EVM TxBroadcasted', [
                'txHash' => $e->txHash,
                'fields' => $e->fields,
            ]);
        });

        // Log when transaction mined
        Event::listen(TxMined::class, function (TxMined $e) {
            Log::info('EVM TxMined', [
                'txHash' => $e->txHash,
                'receipt' => $e->receipt,
            ]);
        });

        // Log replacement attempts
        Event::listen(TxReplaced::class, function (TxReplaced $e) {
            Log::info('EVM TxReplaced', [
                'oldTxHash' => $e->oldTxHash,
                'attempt' => $e->attempt,
                'newFields' => $e->newFields,
            ]);
        });

        // Log failures
        Event::listen(TxFailed::class, function (TxFailed $e) {
            Log::warning('EVM TxFailed', [
                'to' => $e->to,
                'data' => $e->data,
                'reason' => $e->reason,
            ]);
        });
    }
}
