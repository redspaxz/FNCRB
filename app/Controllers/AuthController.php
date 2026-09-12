<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Database;
use App\Core\Rbac;
use App\Core\View;

final class AuthController
{
    public function showLogin(): void
    {
        if (Auth::check()) { header('Location: ' . Rbac::baseUrl() . '/dashboard'); return; }
        View::render('auth/login', ['error' => null, 'email' => ''], null);
    }

    public function login(): void
    {
        Csrf::verify();
        $email = strtolower(trim((string)($_POST['email'] ?? '')));
        $pw    = (string)($_POST['password'] ?? '');
        $ip    = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

        if ($email === '' || $pw === '') {
            View::render('auth/login', ['error' => \App\Core\Lang::t('err_credentials'), 'email' => $email], null);
            return;
        }
        if (empty($_POST['terms_accepted'])) {
            Audit::log('LOGIN_TERMS_REFUSED', null, ['email' => $email]);
            View::render('auth/login', ['error' => \App\Core\Lang::t('err_terms'), 'email' => $email], null);
            return;
        }
        if (Auth::throttled($email, $ip)) {
            Audit::log('LOGIN_THROTTLED', null, ['email' => $email]);
            View::render('auth/login', ['error' => \App\Core\Lang::t('err_throttled'), 'email' => $email], null);
            return;
        }

        $stmt = Database::pdo()->prepare(
            "SELECT u.*, r.code AS role_code, i.code AS institution_code
             FROM users u
             JOIN roles r ON r.id = u.role_id
             LEFT JOIN institutions i ON i.id = u.institution_id
             WHERE u.email = ?"
        );
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if (!$user || !password_verify($pw, $user['password_hash'])) {
            Auth::recordAttempt($email, $ip, false);
            Audit::log('LOGIN_FAILED', null, ['email' => $email]);
            View::render('auth/login', ['error' => \App\Core\Lang::t('err_invalid'), 'email' => $email], null);
            return;
        }
        if ($user['status'] !== 'ACTIVE') {
            Auth::recordAttempt($email, $ip, false);
            View::render('auth/login', ['error' => \App\Core\Lang::t('err_account') . ' ' . strtolower($user['status']) . App\Core\Lang::t('err_contact_admin'), 'email' => $email], null);
            return;
        }

        Auth::recordAttempt($email, $ip, true);
        Auth::login($user);
        $_SESSION['terms_accepted_at'] = date('c');
        Rbac::loadForUser((int)$user['role_id']);
        Database::pdo()->prepare("UPDATE users SET last_login_at = NOW() WHERE id = ?")->execute([$user['id']]);
        Audit::log('LOGIN_SUCCESS', ['type' => 'user', 'id' => $user['id']], ['terms_version' => 'T&C-2026-09']);
        header('Location: ' . Rbac::baseUrl() . '/dashboard');
    }

    public function logout(): void
    {
        Audit::log('LOGOUT', ['type' => 'user', 'id' => Auth::id()]);
        Auth::logout();
        header('Location: ' . Rbac::baseUrl() . '/login');
    }
}
