<?php
declare(strict_types=1);

class Auth
{
    public static function startSession(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
            session_start([
                'cookie_httponly' => true,
                'cookie_secure'   => $secure,
                'cookie_samesite' => 'Strict',
                'use_strict_mode' => 1,
                'sid_length'      => 64,
                'sid_bits_per_character' => 6,
            ]);
        }
    }

    public static function requireLogin(): void
    {
        self::startSession();
        if (empty($_SESSION['user_id'])) {
            header('Location: login.php');
            exit;
        }
    }

    public static function requireAdmin(): void
    {
        self::requireLogin();
        if (empty($_SESSION['is_admin'])) {
            http_response_code(403);
            echo 'Admin privileges are required to access this page.';
            exit;
        }
    }

    public static function logout(): void
    {
        self::startSession();
        $_SESSION = [];

        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(
                session_name(),
                '',
                time() - 42000,
                $params['path'],
                $params['domain'],
                $params['secure'],
                $params['httponly']
            );
        }

        session_destroy();
    }
}
