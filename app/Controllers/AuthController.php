<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Crypto;
use App\Core\Csrf;
use App\Core\Database;
use App\Core\Lang;
use App\Core\Rbac;
use App\Core\Totp;
use App\Core\View;

final class AuthController
{
    public const TERMS_VERSION = 'T&C-2026-09';

    public function showLogin(): void
    {
        if (Auth::check()) { header('Location: ' . Rbac::baseUrl() . '/dashboard'); return; }
        $notice = $_SESSION['_flash_login'] ?? null;
        unset($_SESSION['_flash_login']);
        View::render('auth/login', ['error' => null, 'notice' => $notice, 'email' => ''], null);
    }

    public function login(): void
    {
        Csrf::verify();
        $email = strtolower(trim((string)($_POST['email'] ?? '')));
        $pw    = (string)($_POST['password'] ?? '');
        $ip    = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        $fail = fn(string $msg, bool $otp = false) => View::render('auth/login',
            ['error' => $msg, 'email' => $email, 'need_otp' => $otp], null);

        if ($email === '' || $pw === '' || strlen($email) > 190 || strlen($pw) > 1024) { $fail(Lang::t('err_credentials')); return; }
        if (empty($_POST['terms_accepted'])) {
            Audit::log('LOGIN_TERMS_REFUSED', null, ['email' => $email]);
            $fail(Lang::t('err_terms'));
            return;
        }
        if (Auth::ipThrottled($ip)) {
            Audit::log('LOGIN_THROTTLED', null, ['email' => $email, 'scope' => 'ip']);
            $fail(Lang::t('err_throttled'));
            return;
        }

        $stmt = Database::pdo()->prepare(
            "SELECT u.*, r.code AS role_code, i.code AS institution_code, i.status AS inst_status,
                    (u.locked_until IS NOT NULL AND u.locked_until > NOW()) AS is_locked
             FROM users u
             JOIN roles r ON r.id = u.role_id
             LEFT JOIN institutions i ON i.id = u.institution_id
             WHERE u.email = ?"
        );
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if ($user && Auth::accountLocked($user)) {
            Auth::recordAttempt($email, $ip, false);
            Audit::log('LOGIN_THROTTLED', ['type' => 'user', 'id' => $user['id']], ['scope' => 'account']);
            $fail(Lang::t('err_throttled'));
            return;
        }
        if (!$user || !password_verify($pw, $user['password_hash'])) {
            if (!$user) password_hash($pw, PASSWORD_BCRYPT); // equalize timing (no user enumeration)
            Auth::recordAttempt($email, $ip, false, $user ? (int)$user['id'] : null);
            Audit::log('LOGIN_FAILED', null, ['email' => $email]);
            $fail(Lang::t('err_invalid'));
            return;
        }
        if ($user['status'] !== 'ACTIVE') {
            Auth::recordAttempt($email, $ip, false);
            Audit::log('LOGIN_BLOCKED', ['type' => 'user', 'id' => $user['id']], ['status' => $user['status']]);
            $fail(Lang::t('err_account') . ' ' . strtolower($user['status']) . Lang::t('err_contact_admin'));
            return;
        }
        if ($user['institution_id'] !== null && $user['inst_status'] !== 'ACTIVE') {
            Auth::recordAttempt($email, $ip, false);
            Audit::log('LOGIN_BLOCKED', ['type' => 'user', 'id' => $user['id']], ['institution_status' => $user['inst_status']]);
            $fail('Your institution\'s registry access is ' . strtolower((string)$user['inst_status']) . Lang::t('err_contact_admin'));
            return;
        }

        // Second factor (TOTP) for accounts with 2FA enrolled — codes are single-use
        if (!empty($user['totp_secret'])) {
            $otp = preg_replace('/\D/', '', (string)($_POST['otp'] ?? ''));
            if ($otp === '') {
                Audit::log('MFA_CHALLENGE', ['type' => 'user', 'id' => $user['id']]);
                View::render('auth/login', ['error' => null, 'email' => $email, 'need_otp' => true], null);
                return;
            }
            $secret = Crypto::decrypt((string)$user['totp_secret']);
            $last = $user['totp_last_step'] !== null ? (int)$user['totp_last_step'] : null;
            $step = $secret !== null ? Totp::verifyStep($secret, $otp, $last) : null;
            if ($step === null) {
                Auth::recordAttempt($email, $ip, false, (int)$user['id']);
                Audit::log('MFA_FAILED', ['type' => 'user', 'id' => $user['id']]);
                $fail(Lang::t('err_otp'), true);
                return;
            }
            Database::pdo()->prepare("UPDATE users SET totp_last_step = ? WHERE id = ?")->execute([$step, $user['id']]);
            if (!str_starts_with((string)$user['totp_secret'], 'enc:')) { // migrate legacy plaintext seed
                Database::pdo()->prepare("UPDATE users SET totp_secret = ? WHERE id = ?")->execute([Crypto::encrypt($secret), $user['id']]);
            }
        }

        Auth::recordAttempt($email, $ip, true, (int)$user['id']);
        if (password_needs_rehash($user['password_hash'], PASSWORD_BCRYPT)) {
            Database::pdo()->prepare("UPDATE users SET password_hash = ? WHERE id = ?")
                ->execute([password_hash($pw, PASSWORD_BCRYPT), $user['id']]);
        }
        Auth::login($user);
        $_SESSION['terms_accepted_at'] = date('c');
        Rbac::loadForUser((int)$user['role_id']);
        Database::pdo()->prepare("UPDATE users SET last_login_at = NOW() WHERE id = ?")->execute([$user['id']]);
        Audit::log('LOGIN_SUCCESS', ['type' => 'user', 'id' => $user['id']], ['terms_version' => self::TERMS_VERSION]);
        header('Location: ' . Rbac::baseUrl() . ((int)$user['must_change_password'] === 1 ? '/account?must_change=1' : '/dashboard'));
    }

    public function confirmLogout(): void
    {
        if (!Auth::check()) { header('Location: ' . Rbac::baseUrl() . '/login'); return; }
        View::render('auth/logout', []);
    }

    public function logout(): void
    {
        Csrf::verify();
        if (Auth::check()) Audit::log('LOGOUT', ['type' => 'user', 'id' => Auth::id()]);
        Auth::logout();
        header('Location: ' . Rbac::baseUrl() . '/login');
    }
}
