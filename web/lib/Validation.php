<?php

declare(strict_types=1);

namespace GfServer;

/**
 * Centralised input rules. Each method returns void on success and throws
 * ValidationException on failure.
 */
final class Validation
{
    /** Username: 4-20 chars, ASCII letters/digits/underscore only. */
    public static function username(string $username): void
    {
        if (preg_match('/^[A-Za-z0-9_]{4,20}$/', $username) !== 1) {
            throw new ValidationException(
                'Username must be 4-20 characters: letters, digits, underscore.'
            );
        }
    }

    /** Password: 8-72 characters (72 is the bcrypt input limit). */
    public static function password(string $password): void
    {
        $length = strlen($password);
        if ($length < 8 || $length > 72) {
            throw new ValidationException(
                'Password must be between 8 and 72 characters.'
            );
        }
    }

    /** Email address: must be syntactically valid and at most 254 chars. */
    public static function email(string $email): void
    {
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false || strlen($email) > 254) {
            throw new ValidationException('Please enter a valid email address.');
        }
    }

    /** Player or sprite name: 4-16 chars, no whitespace. */
    public static function characterName(string $name): void
    {
        if (preg_match('/\s/', $name) === 1) {
            throw new ValidationException('Name must not contain whitespace.');
        }
        $length = mb_strlen($name);
        if ($length < 4 || $length > 16) {
            throw new ValidationException('Name must be 4-16 characters.');
        }
    }
}
