<?php
declare(strict_types=1);

namespace App\Core;

use PDO;

/**
 * Role-Based Access Control. Granular by role, institution scope and officer level.
 * Permission model: role -> permissions; institution scoping enforced in queries.
 * Permissions are reloaded on every request by Auth::enforce(), so role changes
 * take effect immediately. Machine clients authenticate through ApiGuard.
 */
final class Rbac
{
    private static ?array $userPerms = null;

    public static function loadForUser(int $roleId): void
    {
        $stmt = Database::pdo()->prepare(
            "SELECT p.code FROM role_permissions rp
             JOIN permissions p ON p.id = rp.permission_id
             WHERE rp.role_id = ?"
        );
        $stmt->execute([$roleId]);
        self::$userPerms = $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
        $_SESSION['_perms'] = self::$userPerms;
    }

    public static function can(string $perm): bool
    {
        if (!Auth::check()) return false;
        if (self::$userPerms === null) {
            self::$userPerms = $_SESSION['_perms'] ?? [];
        }
        return in_array($perm, self::$userPerms, true);
    }

    /** Web guard: redirect to login/denied. */
    public static function require(string $perm): void
    {
        self::requireAny([$perm]);
    }

    /** Web guard satisfied by any one of the listed permissions. */
    public static function requireAny(array $perms): void
    {
        if (!Auth::check()) {
            $accept = (string)($_SERVER['HTTP_ACCEPT'] ?? '');
            if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET' || str_contains($accept, 'application/json')) {
                Response::json(['error' => ['code' => 'AUTH_REQUIRED', 'message' => 'Session required.']], 401);
            }
            header('Location: ' . self::baseUrl() . '/login');
            exit;
        }
        foreach ($perms as $p) {
            if (self::can($p)) return;
        }
        Audit::log('ACCESS_DENIED', null, ['permission' => implode('|', $perms), 'uri' => $_SERVER['REQUEST_URI'] ?? '']);
        http_response_code(403);
        if (str_contains((string)($_SERVER['CONTENT_TYPE'] ?? ''), 'application/json')) {
            Response::json(['error' => ['code' => 'FORBIDDEN', 'message' => 'Permission denied.']], 403);
        }
        (new \App\Controllers\PageController())->forbidden();
        exit;
    }

    /** Base URL auto-detected from the front controller's location. */
    public static function baseUrl(): string
    {
        return rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
    }
}
