<?php

declare(strict_types=1);

namespace GfServer\App;

/**
 * Thin wrapper around PHP sessions with hardened cookie settings.
 * get/set/remove operate on $_SESSION; start()/regenerate()/destroy() manage
 * the underlying PHP session and are exercised at runtime behind the front
 * controller.
 */
final class Session
{
    /** Start the session with hardened cookie parameters (idempotent). */
    public function start(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'httponly' => true,
            'secure' => true,
            'samesite' => 'Lax',
        ]);
        session_name('GFSESSID');
        session_start();
    }

    public function get(string $key): mixed
    {
        return $_SESSION[$key] ?? null;
    }

    public function set(string $key, mixed $value): void
    {
        $_SESSION[$key] = $value;
    }

    public function remove(string $key): void
    {
        unset($_SESSION[$key]);
    }

    /** Regenerate the session id (call on login to prevent fixation). */
    public function regenerate(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
    }

    public function destroy(): void
    {
        $_SESSION = [];
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
    }
}
