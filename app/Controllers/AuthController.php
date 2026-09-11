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
            View::render('auth/login', ['error' => 'Email and password are required.', 'email' => $email], null);
            return;
        }
        if (Auth::throttled($email, $ip)) {
            Audit::log('LOGIN_THROTTLED', null, ['email' => $email]);
            View::render('auth/login', ['error' => 'Too many failed attempts. Account temporarily locked — try again later.', 'email' => $email], null);
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
            View::render('auth/login', ['error' => 'Invalid credentials.', 'email' => $email], null);
            return;
        }
        if ($user['status'] !== 'ACTIVE') {
            Auth::recordAttempt($email, $ip, false);
            View::render('auth/login', ['error' => 'Account is ' . strtolower($user['status']) . '. Contact your administrator.', 'email' => $email], null);
            return;
        }

        Auth::recordAttempt($email, $ip, true);
        Auth::login($user);
        Rbac::loadForUser((int)$user['role_id']);
        Database::pdo()->prepare("UPDATE users SET last_login_at = NOW() WHERE id = ?")->execute([$user['id']]);
        Audit::log('LOGIN_SUCCESS', ['type' => 'user', 'id' => $user['id']]);
        header('Location: ' . Rbac::baseUrl() . '/dashboard');
    }

    public function logout(): void
    {
        Audit::log('LOGOUT', ['type' => 'user', 'id' => Auth::id()]);
        Auth::logout();
        header('Location: ' . Rbac::baseUrl() . '/login');
    }
}
