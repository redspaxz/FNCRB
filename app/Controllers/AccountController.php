<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Crypto;
use App\Core\Csrf;
use App\Core\Database;
use App\Core\Http;
use App\Core\Rbac;
use App\Core\Response;
use App\Core\Totp;
use App\Core\Validator;
use App\Core\View;

/**
 * Self-service account: password change (policy-enforced, revokes other
 * sessions) and TOTP 2FA enrolment / removal (re-authentication required).
 */
final class AccountController
{
    private function guard(): void
    {
        if (!Auth::check()) { header('Location: ' . Rbac::baseUrl() . '/login'); exit; }
    }

    public function index(): void
    {
        $this->guard();
        $this->render([]);
    }

    public function changePassword(): void
    {
        $this->guard();
        Csrf::verify();
        $cur = (string)($_POST['current_password'] ?? '');
        $new = (string)($_POST['new_password'] ?? '');
        $confirm = (string)($_POST['confirm_password'] ?? '');

        $stmt = Database::pdo()->prepare("SELECT password_hash, email FROM users WHERE id = ?");
        $stmt->execute([Auth::id()]);
        $row = $stmt->fetch();

        $error = null;
        if (!$row || !password_verify($cur, $row['password_hash'])) $error = 'Current password is incorrect.';
        elseif ($new !== $confirm) $error = 'New password and confirmation do not match.';
        elseif (!Validator::password($new)) $error = 'Password must be at least 10 characters with upper, lower case and a digit.';
        elseif (password_verify($new, $row['password_hash'])) $error = 'New password must differ from the current one.';
        elseif (stripos($new, explode('@', (string)$row['email'])[0]) !== false) $error = 'Password must not contain your e-mail name.';

        if ($error) {
            Audit::log('PASSWORD_CHANGE_FAILED', ['type' => 'user', 'id' => Auth::id()]);
            $this->render(['error' => $error]);
            return;
        }

        Database::pdo()->prepare("UPDATE users SET password_hash = ?, must_change_password = 0 WHERE id = ?")
            ->execute([password_hash($new, PASSWORD_BCRYPT), Auth::id()]);
        Auth::bumpSessionVersion((int)Auth::id()); // signs out every other session
        $_SESSION['_must_change_password'] = false;
        Audit::log('PASSWORD_CHANGED', ['type' => 'user', 'id' => Auth::id()]);
        $this->render(['msg' => 'Password changed successfully. Other sessions have been signed out.']);
    }

    /** Step 1: generate and display a pending secret (not yet active until verified). */
    public function start2fa(): void
    {
        $this->guard();
        Csrf::verify();
        $_SESSION['_totp_pending'] = Totp::generateSecret();
        $this->render([]);
    }

    /** Step 2: verify a code from the app; only then activate the secret. */
    public function confirm2fa(): void
    {
        $this->guard();
        Csrf::verify();
        $code = preg_replace('/\D/', '', (string)($_POST['otp'] ?? ''));
        $secret = $_SESSION['_totp_pending'] ?? '';
        $step = $secret !== '' ? Totp::verifyStep($secret, $code) : null;
        if ($step === null) {
            $this->render(['error2' => 'Code invalid — 2FA not activated. Try again.']);
            return;
        }
        Database::pdo()->prepare("UPDATE users SET totp_secret = ?, totp_last_step = ? WHERE id = ?")
            ->execute([Crypto::encrypt($secret), $step, Auth::id()]);
        unset($_SESSION['_totp_pending']);
        Audit::log('MFA_ENABLED', ['type' => 'user', 'id' => Auth::id()]);
        $this->render(['msg2' => 'Two-factor authentication is now active for your account.']);
    }

    /** Removing 2FA requires the password AND a current code (step-up re-authentication). */
    public function disable2fa(): void
    {
        $this->guard();
        Csrf::verify();
        $stmt = Database::pdo()->prepare("SELECT password_hash, totp_secret, totp_last_step FROM users WHERE id = ?");
        $stmt->execute([Auth::id()]);
        $u = $stmt->fetch();
        $secret = $u && $u['totp_secret'] ? Crypto::decrypt((string)$u['totp_secret']) : null;
        $otp = preg_replace('/\D/', '', (string)($_POST['otp'] ?? ''));
        if (!$u || !password_verify((string)($_POST['current_password'] ?? ''), $u['password_hash'])
            || $secret === null || Totp::verifyStep($secret, $otp, $u['totp_last_step'] !== null ? (int)$u['totp_last_step'] : null) === null) {
            Audit::log('MFA_DISABLE_FAILED', ['type' => 'user', 'id' => Auth::id()]);
            $this->render(['error2' => 'Password or one-time code incorrect — 2FA remains active.']);
            return;
        }
        Database::pdo()->prepare("UPDATE users SET totp_secret = NULL, totp_last_step = NULL WHERE id = ?")->execute([Auth::id()]);
        Auth::bumpSessionVersion((int)Auth::id());
        Audit::log('MFA_DISABLED', ['type' => 'user', 'id' => Auth::id()]);
        $this->render(['msg2' => 'Two-factor authentication disabled.']);
    }

    private function render(array $flash): void
    {
        $stmt = Database::pdo()->prepare("SELECT email, totp_secret, must_change_password FROM users WHERE id = ?");
        $stmt->execute([Auth::id()]);
        $me = $stmt->fetch();
        if (!$me) { Auth::logout(); header('Location: ' . Rbac::baseUrl() . '/login'); exit; }
        View::render('account/index', array_merge(['msg' => null, 'error' => null, 'msg2' => null, 'error2' => null], $flash, [
            'me' => $me,
            'mustChange' => (int)$me['must_change_password'] === 1,
            'pendingSecret' => $_SESSION['_totp_pending'] ?? null,
            'otpauthUri' => isset($_SESSION['_totp_pending'])
                ? Totp::otpauthUri($_SESSION['_totp_pending'], $me['email'])
                : null,
        ]));
    }
}

/**
 * User administration (joiner/mover/leaver lifecycle).
 * INST_ADMIN manages own institution; SUPER_ADMIN manages everything,
 * including national (regulator / bureau) accounts.
 */
final class UserController
{
    private const INSTITUTION_ROLES = ['INST_ADMIN', 'COMPLIANCE', 'CREDIT_OFFICER', 'AUDITOR'];
    private const NATIONAL_ROLES = ['SUPER_ADMIN', 'REGULATOR'];

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
        $base = "FROM users u JOIN roles r ON r.id = u.role_id LEFT JOIN institutions i ON i.id = u.institution_id";
        if ($this->isSuper()) {
            $total = (int)$pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
            $stmt = $pdo->query(
                "SELECT u.*, r.code AS role_code, i.code AS inst_code $base
                 ORDER BY u.institution_id IS NULL DESC, i.code, u.full_name LIMIT $perPage OFFSET $offset"
            );
        } else {
            $c = $pdo->prepare("SELECT COUNT(*) FROM users WHERE institution_id = ?");
            $c->execute([Auth::institutionId()]);
            $total = (int)$c->fetchColumn();
            $stmt = $pdo->prepare(
                "SELECT u.*, r.code AS role_code, i.code AS inst_code $base
                 WHERE u.institution_id = ? ORDER BY u.full_name LIMIT $perPage OFFSET $offset"
            );
            $stmt->execute([Auth::institutionId()]);
        }
        View::render('users/index', [
            'users' => $stmt->fetchAll(), 'isSuper' => $this->isSuper(), 'selfId' => Auth::id(),
            'pager' => \App\Core\Pagination::render('/users', $page, $perPage, $total),
        ]);
    }

    public function create(): void
    {
        Rbac::require('users.manage');
        $this->form(null, null);
    }

    private function form(?string $error, ?array $generated): void
    {
        $codes = $this->isSuper() ? array_merge(self::INSTITUTION_ROLES, self::NATIONAL_ROLES) : self::INSTITUTION_ROLES;
        $in = implode(',', array_fill(0, count($codes), '?'));
        $roles = Database::pdo()->prepare("SELECT * FROM roles WHERE code IN ($in) ORDER BY name");
        $roles->execute($codes);
        $insts = $this->isSuper()
            ? Database::pdo()->query("SELECT id, code, name FROM institutions WHERE category != 'REGULATOR' ORDER BY code")->fetchAll()
            : [];
        View::render('users/create', ['error' => $error, 'roles' => $roles->fetchAll(), 'institutions' => $insts,
            'isSuper' => $this->isSuper(), 'generated' => $generated]);
    }

    public function store(): void
    {
        Rbac::require('users.manage');
        Csrf::verify();

        $name = Validator::string($_POST, 'full_name', 150);
        $email = Validator::email($_POST, 'email');
        $allowed = $this->isSuper() ? array_merge(self::INSTITUTION_ROLES, self::NATIONAL_ROLES) : self::INSTITUTION_ROLES;
        $roleCode = Validator::enum($_POST, 'role_code', $allowed);
        $level = Validator::int($_POST, 'officer_level', 1, 3) ?? 1;
        $branch = Validator::string($_POST, 'branch_code', 30, 0) ?: null;

        if ($this->isSuper()) {
            $instId = in_array($roleCode, self::NATIONAL_ROLES, true) ? null : Validator::int($_POST, 'institution_id', 1);
        } else {
            $instId = Auth::institutionId();
        }

        if (!$name || !$email || !$roleCode) { $this->form('Name, a valid email and a role are required.', null); return; }
        if (!in_array($roleCode, self::NATIONAL_ROLES, true) && !$instId) { $this->form('Institution roles require an institution.', null); return; }
        if ($instId) {
            $ok = Database::pdo()->prepare("SELECT 1 FROM institutions WHERE id = ? AND category != 'REGULATOR'");
            $ok->execute([$instId]);
            if (!$ok->fetchColumn()) { $this->form('Unknown institution.', null); return; }
        }
        $dup = Database::pdo()->prepare("SELECT id FROM users WHERE email = ?");
        $dup->execute([$email]);
        if ($dup->fetch()) { $this->form('A user with this email already exists.', null); return; }

        $rid = Database::pdo()->prepare("SELECT id FROM roles WHERE code = ?");
        $rid->execute([$roleCode]);
        $roleId = (int)$rid->fetchColumn();

        $password = Validator::generatePassword();
        Database::pdo()->prepare(
            "INSERT INTO users (institution_id, role_id, full_name, email, password_hash, officer_level, branch_code, status, must_change_password)
             VALUES (?,?,?,?,?,?,?,'ACTIVE',1)"
        )->execute([$instId, $roleId, $name, $email, password_hash($password, PASSWORD_BCRYPT), $level, $branch]);
        $id = (int)Database::pdo()->lastInsertId();

        Audit::log('USER_CREATED', ['type' => 'user', 'id' => $id], ['email' => $email, 'role' => $roleCode, 'institution_id' => $instId]);
        $this->form(null, ['email' => $email, 'password' => $password]);
    }

    /** Load a manageable target user or emit a JSON error. */
    private function target(): array
    {
        $body = Http::input();
        $id = Validator::int($body, 'id', 1);
        if (!$id) Response::json(['error' => 'Invalid target user.'], 422);
        $stmt = Database::pdo()->prepare("SELECT u.*, r.code AS role_code FROM users u JOIN roles r ON r.id=u.role_id WHERE u.id = ?");
        $stmt->execute([$id]);
        $u = $stmt->fetch();
        if (!$u) Response::json(['error' => 'User not found.'], 404);
        if (!$this->isSuper() && ($u['institution_id'] === null || (int)$u['institution_id'] !== (int)Auth::institutionId())) {
            Response::json(['error' => 'You can only manage users of your own institution.'], 403);
        }
        if (!$this->isSuper() && in_array($u['role_code'], self::NATIONAL_ROLES, true)) {
            Response::json(['error' => 'National accounts are managed by the bureau.'], 403);
        }
        return $u;
    }

    /** Toggle ACTIVE/LOCKED (leaver/mover). Locking ends the user's sessions immediately. */
    public function toggle(): void
    {
        Rbac::require('users.manage');
        Csrf::verifyJson();
        $u = $this->target();
        if ((int)$u['id'] === Auth::id()) Response::json(['error' => 'You cannot lock your own account.'], 422);
        if ($u['role_code'] === 'SUPER_ADMIN' && !$this->isSuper()) Response::json(['error' => 'Cannot lock a system administrator.'], 403);

        $new = $u['status'] === 'ACTIVE' ? 'LOCKED' : 'ACTIVE';
        Database::pdo()->prepare("UPDATE users SET status = ?, failed_logins = 0, locked_until = NULL WHERE id = ?")->execute([$new, $u['id']]);
        Auth::bumpSessionVersion((int)$u['id']);
        Audit::log($new === 'ACTIVE' ? 'USER_ACTIVATED' : 'USER_LOCKED', ['type' => 'user', 'id' => $u['id']]);
        Response::json(['ok' => true, 'id' => (int)$u['id'], 'status' => $new]);
    }

    /** Admin-forced password reset: new one-time password, change required at next sign-in. */
    public function resetPassword(): void
    {
        Rbac::require('users.manage');
        Csrf::verifyJson();
        $u = $this->target();
        if ((int)$u['id'] === Auth::id()) Response::json(['error' => 'Use My Account to change your own password.'], 422);
        $password = Validator::generatePassword();
        Database::pdo()->prepare("UPDATE users SET password_hash = ?, must_change_password = 1, failed_logins = 0, locked_until = NULL WHERE id = ?")
            ->execute([password_hash($password, PASSWORD_BCRYPT), $u['id']]);
        Auth::bumpSessionVersion((int)$u['id']);
        Audit::log('PASSWORD_RESET_BY_ADMIN', ['type' => 'user', 'id' => $u['id']]);
        Response::json(['ok' => true, 'password' => $password]);
    }

    /** Remove a user's 2FA enrolment (lost device) — they must re-enrol. */
    public function reset2fa(): void
    {
        Rbac::require('users.manage');
        Csrf::verifyJson();
        $u = $this->target();
        if ((int)$u['id'] === Auth::id()) Response::json(['error' => 'Use My Account to manage your own 2FA.'], 422);
        Database::pdo()->prepare("UPDATE users SET totp_secret = NULL, totp_last_step = NULL WHERE id = ?")->execute([$u['id']]);
        Auth::bumpSessionVersion((int)$u['id']);
        Audit::log('MFA_RESET_BY_ADMIN', ['type' => 'user', 'id' => $u['id']]);
        Response::json(['ok' => true]);
    }
}
