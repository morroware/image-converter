<?php
declare(strict_types=1);

namespace Castle;

/**
 * Tiny helpers for JSON responses and CSRF token handling.
 */
final class Response
{
    public static function json(array $data, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }

    public static function error(string $message, int $status = 400, array $extra = []): void
    {
        self::json(array_merge(['error' => $message], $extra), $status);
    }

    public static function csrfToken(): string
    {
        return $_SESSION['csrf'] ?? '';
    }

    /**
     * Validate a submitted CSRF token against the session token.
     * Accepts either a POST field ("_csrf") or the X-CSRF-Token header.
     */
    public static function verifyCsrf(): bool
    {
        $sent = $_POST['_csrf']
            ?? $_SERVER['HTTP_X_CSRF_TOKEN']
            ?? '';
        $expected = $_SESSION['csrf'] ?? '';
        return is_string($sent) && $expected !== '' && hash_equals($expected, $sent);
    }

    public static function requireCsrf(): void
    {
        if (!self::verifyCsrf()) {
            self::error('Invalid or missing CSRF token', 403);
        }
    }
}
