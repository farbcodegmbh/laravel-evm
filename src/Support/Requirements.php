<?php

namespace Farbcode\LaravelEvm\Support;

use Farbcode\LaravelEvm\Exceptions\RequirementException;

/**
 * Runtime prerequisites, checked where they are actually needed.
 *
 * composer.json declares ext-gmp, which is the primary safeguard: composer
 * refuses to install without it. That check only runs where composer runs,
 * though, so it misses --ignore-platform-reqs, a prebuilt vendor/ shipped to
 * another machine, and a PHP-FPM pool whose extension set differs from the CLI
 * that installed. This guard covers those, with a message worth reading -
 * without it the failure is "Call to undefined function gmp_init()".
 */
class Requirements
{
    private static bool $gmpChecked = false;

    public static function gmp(): void
    {
        if (self::$gmpChecked) {
            return;
        }

        if (! extension_loaded('gmp')) {
            throw new RequirementException(
                'The GMP PHP extension is required for laravel-evm. Enable it with '.
                '`apt install php-gmp` (or the equivalent for your platform) and restart PHP-FPM / CLI.'
            );
        }

        self::$gmpChecked = true;
    }
}
