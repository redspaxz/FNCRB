<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Session hardening, authentication, login throttling (OWASP ASVS 2.x / 6.2).
 */
final class Auth
{
    public static function start(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) return;
        $c = require dirname(__DIR__, 2) . '/config/config.php';
        session_name($c['security']['session_name']);
        session_set_cookie_params([
            'lifetime' => 0,
            'httponly' => true,
            'samesite' => 'Lax',
            'secure'   => ($c['app']['env'] === 'production'),
            'path'     => '/',
        ]);
        session_start();
        // session fixation protection
        if (!isset($_SESSION['_created'])) {
            $_SESSION['_created'] = time();
        } elseif (time() - (int)$_SESSION['_created'] > $c['security']['session_lifetime']) {
            session_regenerate_id(true);
            $_SESSION['_created'] = time();
        }
    }

    public static function user(): ?array
    {
        return $_SESSION['user'] ?? null;
    }

    public static function id(): ?int
    {
        return isset($_SESSION['user']['id']) ? (int)$_SESSION['user']['id'] : null;
    }

    public static function institutionId(): ?int
    {
        return isset($_SESSION['user']['institution_id']) ? (int)$_SESSION['user']['institution_id'] : null;
    }

    public static function check(): bool
    {
        return isset($_SESSION['user']);
    }

    public static function login(array $user): void
    {
        session_regenerate_id(true);
        $_SESSION['user'] = [
            'id'             => (int)$user['id'],
            'full_name'      => $user['full_name'],
            'email'          => $user['email'],
            'role_code'      => $user['role_code'],
            'institution_id' => $user['institution_id'] !== null ? (int)$user['institution_id'] : null,
            'institution_code' => $user['institution_code'] ?? null,
            'officer_level'  => (int)$user['officer_level'],
        ];
    }

    public static function logout(): void
    {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
        }
        session_destroy();
    }

    /** Track failed attempts per email+IP; returns true if throttled. */
    public static function throttled(string $email, string $ip): bool
    {
        $c = require dirname(__DIR__, 2) . '/config/config.php';
        $max = $c['security']['max_failed_logins'];
        $window = $c['security']['lockout_minutes'] * 60;
        $stmt = Database::pdo()->prepare(
            "SELECT COUNT(*) FROM login_attempts
             WHERE email = ? AND ip_address = ? AND success = 0
               AND created_at > (NOW() - INTERVAL ? SECOND)"
        );
        $stmt->execute([$email, $ip, $window]);
        return (int)$stmt->fetchColumn() >= $max;
    }

    public static function recordAttempt(string $email, string $ip, bool $success): void
    {
        $stmt = Database::pdo()->prepare(
            "INSERT INTO login_attempts (email, ip_address, success) VALUES (?,?,?)"
        );
        $stmt->execute([$email, $ip, $success ? 1 : 0]);
    }
}
