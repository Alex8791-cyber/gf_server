<?php

declare(strict_types=1);

namespace GfServer\App;

/**
 * CSRF token helpers. The caller stores generate()'s result in the session
 * and passes it (with the value submitted by the form) to check().
 */
final class Csrf
{
    /** Produce a fresh random CSRF token. */
    public static function generate(): string
    {
        return bin2hex(random_bytes(32));
    }

    /** Constant-time compare of the session token against the submitted one. */
    public static function check(?string $stored, ?string $submitted): bool
    {
        if ($stored === null || $submitted === null || $stored === '') {
            return false;
        }

        return hash_equals($stored, $submitted);
    }
}
