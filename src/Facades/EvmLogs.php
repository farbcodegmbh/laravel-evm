<?php

namespace Farbcode\LaravelEvm\Facades;

use Illuminate\Support\Facades\Facade;
use Farbcode\LaravelEvm\Support\LogFilterBuilder;

class EvmLogs extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return LogFilterBuilder::class;
    }

    /**
     * Start a new log filter builder.
     */
    public static function query(): LogFilterBuilder
    {
        /** @var LogFilterBuilder $builder */
        $builder = app(LogFilterBuilder::class);
        return $builder;
    }
}
