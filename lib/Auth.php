<?php
declare(strict_types=1);

namespace Castle;

/**
 * Optional session-based password gate.
 * Controlled by CASTLE_AUTH_ENABLED + CASTLE_AUTH_HASH constants.
 */
final class Auth
{
    public static function isEnabled(): bool
    {
        return defined('CASTLE_AUTH_ENABLED') && CASTLE_AUTH_ENABLED === true;
    }

    public static function isAuthenticated(): bool
    {
        if (!self::isEnabled()) {
            return true;
        }
        return !empty($_SESSION['castle_authed']);
    }

    public static function attempt(string $password): bool
    {
        $hash = defined('CASTLE_AUTH_HASH') ? CASTLE_AUTH_HASH : '';
        if ($hash === '' || !password_verify($password, $hash)) {
            // Small timing-attack mitigation.
            usleep(random_int(50000, 150000));
            return false;
        }

        // Regenerate session ID on privilege change.
        session_regenerate_id(true);
        if (empty($_SESSION['csrf'])) {
            $_SESSION['csrf'] = bin2hex(random_bytes(32));
        }
        $_SESSION['castle_authed']  = true;
        $_SESSION['castle_login_t'] = time();
        return true;
    }

    public static function logout(): void
    {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000,
                $p['path'], $p['domain'], $p['secure'], $p['httponly']);
        }
        session_destroy();
    }
}
