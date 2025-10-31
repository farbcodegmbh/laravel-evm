<?php

namespace Farbcode\LaravelEvm\Facades;

use Farbcode\LaravelEvm\Support\LogFilterBuilder;
use Illuminate\Support\Facades\Facade;

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
