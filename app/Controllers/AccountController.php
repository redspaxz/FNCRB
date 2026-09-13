<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Database;
use App\Core\Rbac;
use App\Core\Totp;
use App\Core\Validator;
use App\Core\View;

/**
 * Self-service account: password change (policy-enforced) and TOTP 2FA enrollment.
 */
final class AccountController
{
    public function index(): void
    {
        Auth::start();
        if (!Auth::check()) { header('Location: ' . Rbac::baseUrl() . '/login'); return; }
        $this->render(['msg' => null, 'error' => null, 'msg2' => null, 'error2' => null]);
    }

    public function changePassword(): void
    {
        Csrf::verify();
        $cur = (string)($_POST['current_password'] ?? '');
        $new = (string)($_POST['new_password'] ?? '');
        $confirm = (string)($_POST['confirm_password'] ?? '');

        $stmt = Database::pdo()->prepare("SELECT password_hash FROM users WHERE id = ?");
        $stmt->execute([Auth::id()]);
        $hash = $stmt->fetchColumn();

        $error = null;
        if (!$hash || !password_verify($cur, $hash)) $error = 'Current password is incorrect.';
        elseif ($new !== $confirm) $error = 'New password and confirmation do not match.';
        elseif (!Validator::password($new)) $error = 'Password must be at least 10 characters with upper, lower case and a digit.';
        elseif (password_verify($new, $hash)) $error = 'New password must differ from the current one.';

        if ($error) { $this->render(['error' => $error]); return; }

        Database::pdo()->prepare("UPDATE users SET password_hash = ? WHERE id = ?")
            ->execute([password_hash($new, PASSWORD_BCRYPT), Auth::id()]);
        Audit::log('PASSWORD_CHANGED', ['type' => 'user', 'id' => Auth::id()]);
        $this->render(['msg' => 'Password changed successfully.']);
    }

    /** Step 1: generate and display a pending secret (not yet active until verified). */
    public function start2fa(): void
    {
        Csrf::verify();
        $_SESSION['_totp_pending'] = Totp::generateSecret();
        $this->render(['msg' => null]);
    }

    /** Step 2: verify a code from the app; only then activate the secret. */
    public function confirm2fa(): void
    {
        Csrf::verify();
        $code = preg_replace('/\D/', '', (string)($_POST['otp'] ?? ''));
        $secret = $_SESSION['_totp_pending'] ?? '';
        if ($secret === '' || !Totp::verify($secret, $code)) {
            $this->render(['error2' => 'Code invalid — 2FA not activated. Try again.']);
            return;
        }
        Database::pdo()->prepare("UPDATE users SET totp_secret = ? WHERE id = ?")->execute([$secret, Auth::id()]);
        unset($_SESSION['_totp_pending']);
        Audit::log('MFA_ENABLED', ['type' => 'user', 'id' => Auth::id()]);
        $this->render(['msg2' => 'Two-factor authentication is now active for your account.']);
    }

    public function disable2fa(): void
    {
        Csrf::verify();
        Database::pdo()->prepare("UPDATE users SET totp_secret = NULL WHERE id = ?")->execute([Auth::id()]);
        Audit::log('MFA_DISABLED', ['type' => 'user', 'id' => Auth::id()]);
        $this->render(['msg2' => 'Two-factor authentication disabled.']);
    }

    private function render(array $flash): void
    {
        $stmt = Database::pdo()->prepare("SELECT email, totp_secret FROM users WHERE id = ?");
        $stmt->execute([Auth::id()]);
        $me = $stmt->fetch();
        View::render('account/index', array_merge($flash, [
            'me' => $me,
            'pendingSecret' => $_SESSION['_totp_pending'] ?? null,
            'otpauthUri' => isset($_SESSION['_totp_pending'])
                ? Totp::otpauthUri($_SESSION['_totp_pending'], $me['email'])
                : null,
        ]));
    }
}

/**
 * Institution user administration (joiner/mover/leaver lifecycle).
 * INST_ADMIN manages own institution; SUPER_ADMIN manages everything.
 */
final class UserController
{
    private function isSuper(): bool
    {
        return (Auth::user()['role_code'] ?? '') === 'SUPER_ADMIN';
    }

    public function index(): void
    {
        Rbac::require('users.manage');
        $pdo = Database::pdo();
        $page = \App\Core\Pagination::page();
        $perPage = \App\Core\Pagination::perPage();
        $offset = \App\Core\Pagination::offset($page, $perPage);
        if ($this->isSuper() && isset($_GET['all'])) {
            $total = (int)$pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
            $stmt = $pdo->query(
                "SELECT u.*, r.code AS role_code, i.code AS inst_code FROM users u
                 JOIN roles r ON r.id = u.role_id LEFT JOIN institutions i ON i.id = u.institution_id
                 ORDER BY u.institution_id IS NULL DESC, i.code, u.full_name LIMIT $perPage OFFSET $offset"
            );
        } else {
            $c = $pdo->prepare("SELECT COUNT(*) FROM users WHERE institution_id = ?");
            $c->execute([Auth::institutionId()]);
            $total = (int)$c->fetchColumn();
            $stmt = $pdo->prepare(
                "SELECT u.*, r.code AS role_code, i.code AS inst_code FROM users u
                 JOIN roles r ON r.id = u.role_id LEFT JOIN institutions i ON i.id = u.institution_id
                 WHERE u.institution_id = ? ORDER BY u.full_name LIMIT $perPage OFFSET $offset"
            );
            $stmt->execute([Auth::institutionId()]);
        }
        View::render('users/index', [
            'users' => $stmt->fetchAll(), 'isSuper' => $this->isSuper(),
            'pager' => \App\Core\Pagination::render('/users', $page, $perPage, $total),
        ]);
    }

    public function create(): void
    {
        Rbac::require('users.manage');
        $roles = Database::pdo()->query(
            "SELECT * FROM roles WHERE code NOT IN ('SUPER_ADMIN','REGULATOR') ORDER BY name"
        )->fetchAll();
        View::render('users/create', ['error' => null, 'roles' => $roles, 'generated' => null]);
    }

    public function store(): void
    {
        Rbac::require('users.manage');
        Csrf::verify();

        $name = Validator::string($_POST, 'full_name', 150);
        $email = Validator::email($_POST, 'email');
        $roleCode = Validator::enum($_POST, 'role_code', ['INST_ADMIN','COMPLIANCE','CREDIT_OFFICER','AUDITOR','REGULATOR']);
        $level = Validator::int($_POST, 'officer_level', 1, 3) ?? 1;
        $branch = Validator::string($_POST, 'branch_code', 30, 0) ?: null;

        // institution admins can only create users in their own institution
        $instId = $this->isSuper()
            ? (Validator::int($_POST, 'institution_id', 1) ?? null)
            : Auth::institutionId();
        if (!$this->isSuper() && in_array($roleCode, ['REGULATOR'], true)) $roleCode = null;

        if (!$name || !$email || !$roleCode || !$instId) {
            $roles = $this->rolesList();
            View::render('users/create', ['error' => 'All fields are required (valid email, role, institution).', 'roles' => $roles, 'generated' => null]);
            return;
        }
        $dup = Database::pdo()->prepare("SELECT id FROM users WHERE email = ?");
        $dup->execute([$email]);
        if ($dup->fetch()) {
            View::render('users/create', ['error' => 'A user with this email already exists.', 'roles' => $this->rolesList(), 'generated' => null]);
            return;
        }

        $rid = Database::pdo()->prepare("SELECT id FROM roles WHERE code = ?");
        $rid->execute([$roleCode]);
        $roleId = (int)$rid->fetchColumn();

        $password = 'Fncrb-' . bin2hex(random_bytes(5)) . 'A1'; // meets policy: len>=10, upper/lower/digit
        $stmt = Database::pdo()->prepare(
            "INSERT INTO users (institution_id, role_id, full_name, email, password_hash, officer_level, branch_code, status)
             VALUES (?,?,?,?,?,?,?,'ACTIVE')"
        );
        $stmt->execute([$instId, $roleId, $name, $email, password_hash($password, PASSWORD_BCRYPT), $level, $branch]);
        $id = (int)Database::pdo()->lastInsertId();

        Audit::log('USER_CREATED', ['type' => 'user', 'id' => $id], ['email' => $email, 'role' => $roleCode]);
        View::render('users/create', [
            'error' => null,
            'roles' => $this->rolesList(),
            'generated' => ['email' => $email, 'password' => $password],
        ]);
    }

    /** Toggle ACTIVE/LOCKED (leaver/mover). */
    public function toggle(): void
    {
        Rbac::require('users.manage');
        Csrf::verifyJson();
        $body = json_decode((string)file_get_contents('php://input'), true) ?: $_POST;
        $id = Validator::int($body, 'id', 1);
        if (!$id || $id === Auth::id()) \App\Core\Response::json(['error' => 'Invalid target user.'], 422);

        $stmt = Database::pdo()->prepare("SELECT u.*, r.code AS role_code FROM users u JOIN roles r ON r.id=u.role_id WHERE u.id = ?");
        $stmt->execute([$id]);
        $u = $stmt->fetch();
        if (!$u) \App\Core\Response::json(['error' => 'User not found.'], 404);
        if (!$this->isSuper() && (int)$u['institution_id'] !== (int)Auth::institutionId()) {
            \App\Core\Response::json(['error' => 'You can only manage users of your own institution.'], 403);
        }
        if (in_array($u['role_code'], ['SUPER_ADMIN'], true)) \App\Core\Response::json(['error' => 'Cannot lock a system administrator.'], 403);

        $new = $u['status'] === 'ACTIVE' ? 'LOCKED' : 'ACTIVE';
        Database::pdo()->prepare("UPDATE users SET status = ? WHERE id = ?")->execute([$new, $id]);
        Audit::log($new === 'ACTIVE' ? 'USER_ACTIVATED' : 'USER_LOCKED', ['type' => 'user', 'id' => $id]);
        \App\Core\Response::json(['ok' => true, 'status' => $new]);
    }

    /** Admin-forced password reset: generates a new one-time password. */
    public function resetPassword(): void
    {
        Rbac::require('users.manage');
        Csrf::verifyJson();
        $body = json_decode((string)file_get_contents('php://input'), true) ?: $_POST;
        $id = Validator::int($body, 'id', 1);
        if (!$id) \App\Core\Response::json(['error' => 'Invalid user.'], 422);

        $stmt = Database::pdo()->prepare("SELECT u.*, r.code AS role_code FROM users u JOIN roles r ON r.id=u.role_id WHERE u.id = ?");
        $stmt->execute([$id]);
        $u = $stmt->fetch();
        if (!$u) \App\Core\Response::json(['error' => 'User not found.'], 404);
        if (!$this->isSuper() && (int)$u['institution_id'] !== (int)Auth::institutionId()) {
            \App\Core\Response::json(['error' => 'You can only manage users of your own institution.'], 403);
        }

        $password = 'Fncrb-' . bin2hex(random_bytes(5)) . 'A1';
        Database::pdo()->prepare("UPDATE users SET password_hash = ? WHERE id = ?")
            ->execute([password_hash($password, PASSWORD_BCRYPT), $id]);
        Audit::log('PASSWORD_RESET_BY_ADMIN', ['type' => 'user', 'id' => $id]);
        \App\Core\Response::json(['ok' => true, 'password' => $password]);
    }

    private function rolesList(): array
    {
        return Database::pdo()->query(
            "SELECT * FROM roles WHERE code NOT IN ('SUPER_ADMIN','REGULATOR') ORDER BY name"
        )->fetchAll();
    }
}
