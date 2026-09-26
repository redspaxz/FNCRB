<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Session hardening, authentication, login throttling (OWASP ASVS 2.x / 3.x / 6.2).
 *
 * Sessions are re-validated against the database on every request (enforce()):
 * a locked/disabled user, a suspended institution, a role change or a bumped
 * users.session_version (password change/reset, lock, 2FA reset) ends the
 * session immediately. Idle and absolute timeouts are enforced server-side.
 */
final class Auth
{
    /** Paths reachable while a password change is pending. */
    private const PASSWORD_CHANGE_ALLOWED = ['/account', '/account/password', '/logout', '/terms'];

    public static function start(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) return;
        session_name((string)Config::get('security.session_name'));
        session_set_cookie_params([
            'lifetime' => 0,
            'httponly' => true,
            'samesite' => 'Lax',
            'secure'   => Config::get('app.env') === 'production' || (($_SERVER['HTTPS'] ?? '') !== '' && $_SERVER['HTTPS'] !== 'off'),
            'path'     => '/',
        ]);
        ini_set('session.use_strict_mode', '1');
        session_start();
        // periodic id rotation (fixation protection)
        if (!isset($_SESSION['_created'])) {
            $_SESSION['_created'] = time();
        } elseif (time() - (int)$_SESSION['_created'] > 900) {
            session_regenerate_id(true);
            $_SESSION['_created'] = time();
        }
    }

    /**
     * Re-validate the authenticated session. Call once per web request before
     * dispatch. Redirects/ends the session when it is no longer valid.
     */
    public static function enforce(string $path): void
    {
        if (!self::check()) return;
        $now = time();
        $idle = (int)Config::get('security.session_lifetime', 1800);
        $absolute = (int)Config::get('security.session_absolute', 43200);

        $reason = null;
        if ($now - (int)($_SESSION['_last_activity'] ?? $now) > $idle) $reason = 'idle timeout';
        elseif ($now - (int)($_SESSION['_auth_at'] ?? $now) > $absolute) $reason = 'absolute timeout';

        if ($reason === null) {
            $stmt = Database::pdo()->prepare(
                "SELECT u.id, u.full_name, u.email, u.role_id, u.institution_id, u.officer_level, u.status,
                        u.session_version, u.must_change_password, r.code AS role_code,
                        i.code AS institution_code, i.status AS inst_status
                 FROM users u JOIN roles r ON r.id = u.role_id
                 LEFT JOIN institutions i ON i.id = u.institution_id
                 WHERE u.id = ?"
            );
            $stmt->execute([self::id()]);
            $u = $stmt->fetch();
            if (!$u) $reason = 'account removed';
            elseif ($u['status'] !== 'ACTIVE') $reason = 'account ' . strtolower($u['status']);
            elseif ($u['institution_id'] !== null && $u['inst_status'] !== 'ACTIVE') $reason = 'institution ' . strtolower((string)$u['inst_status']);
            elseif ((int)$u['session_version'] !== (int)($_SESSION['_session_version'] ?? -1)) $reason = 'session revoked';
            else {
                // refresh identity + permissions (role changes apply immediately)
                self::storeUser($u);
                Rbac::loadForUser((int)$u['role_id']);
                $_SESSION['_must_change_password'] = (int)$u['must_change_password'] === 1;
            }
        }

        if ($reason !== null) {
            Audit::log('SESSION_ENDED', ['type' => 'user', 'id' => self::id()], ['reason' => $reason]);
            self::logout();
            self::start();
            $_SESSION['_flash_login'] = 'Your session has ended (' . $reason . '). Please sign in again.';
            self::redirectOrJson('/login', 401);
        }

        $_SESSION['_last_activity'] = $now;

        if (!empty($_SESSION['_must_change_password']) && !in_array($path, self::PASSWORD_CHANGE_ALLOWED, true)) {
            self::redirectOrJson('/account?must_change=1', 403);
        }
    }

    private static function redirectOrJson(string $to, int $status): never
    {
        $accept = (string)($_SERVER['HTTP_ACCEPT'] ?? '');
        if (str_contains($accept, 'application/json') || ($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
            Response::json(['error' => ['code' => 'SESSION_INVALID', 'message' => 'Session expired or password change required.']], $status);
        }
        header('Location: ' . Rbac::baseUrl() . $to);
        exit;
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

    /** National (regulator / bureau) scope: user not attached to a reporting institution. */
    public static function isNational(): bool
    {
        return self::check() && self::institutionId() === null;
    }

    public static function login(array $user): void
    {
        session_regenerate_id(true);
        self::storeUser($user);
        $_SESSION['_auth_at'] = time();
        $_SESSION['_last_activity'] = time();
        $_SESSION['_created'] = time();
        $_SESSION['_session_version'] = (int)($user['session_version'] ?? 1);
        $_SESSION['_must_change_password'] = (int)($user['must_change_password'] ?? 0) === 1;
        $_SESSION['_csrf'] = bin2hex(random_bytes(32)); // fresh CSRF token per authenticated session
    }

    private static function storeUser(array $user): void
    {
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

    /** Invalidate every other session of a user (password change, lock, reset). */
    public static function bumpSessionVersion(int $userId): void
    {
        Database::pdo()->prepare("UPDATE users SET session_version = session_version + 1 WHERE id = ?")->execute([$userId]);
        if ($userId === self::id()) {
            $v = Database::pdo()->prepare("SELECT session_version FROM users WHERE id = ?");
            $v->execute([$userId]);
            $_SESSION['_session_version'] = (int)$v->fetchColumn();
            session_regenerate_id(true);
        }
    }

    public static function logout(): void
    {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
        }
        if (session_status() === PHP_SESSION_ACTIVE) session_destroy();
    }

    /** Source-IP throttle (password spraying across many accounts). */
    public static function ipThrottled(string $ip): bool
    {
        $stmt = Database::pdo()->prepare(
            "SELECT COUNT(*) FROM login_attempts
             WHERE ip_address = ? AND success = 0 AND created_at > (NOW() - INTERVAL ? MINUTE)"
        );
        $stmt->execute([$ip, (int)Config::get('security.lockout_minutes', 15)]);
        return (int)$stmt->fetchColumn() >= (int)Config::get('security.max_failed_per_ip', 20);
    }

    /** Account lock state (independent of source IP). */
    public static function accountLocked(array $user): bool
    {
        if (array_key_exists('is_locked', $user)) return (int)$user['is_locked'] === 1;
        return !empty($user['locked_until']) && strtotime((string)$user['locked_until']) > time();
    }

    public static function recordAttempt(string $email, string $ip, bool $success, ?int $userId = null): void
    {
        Database::pdo()->prepare("INSERT INTO login_attempts (email, ip_address, success) VALUES (?,?,?)")
            ->execute([$email, $ip, $success ? 1 : 0]);
        if ($userId === null) return;
        if ($success) {
            Database::pdo()->prepare("UPDATE users SET failed_logins = 0, locked_until = NULL WHERE id = ?")->execute([$userId]);
            return;
        }
        $max = (int)Config::get('security.max_failed_logins', 5);
        $mins = (int)Config::get('security.lockout_minutes', 15);
        Database::pdo()->prepare(
            "UPDATE users SET failed_logins = IF(locked_until IS NOT NULL AND locked_until <= NOW(), 1, LEAST(failed_logins + 1, 255)),
                    locked_until = IF(failed_logins >= ?, NOW() + INTERVAL ? MINUTE, locked_until)
             WHERE id = ?"
        )->execute([$max, $mins, $userId]);
    }
}
