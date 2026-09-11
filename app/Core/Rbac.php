<?php
declare(strict_types=1);

namespace App\Core;

use PDO;

/**
 * Role-Based Access Control. Granular by role, institution scope and officer level.
 * Permission model: role -> permissions; institution scoping enforced in queries.
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
        if (self::$userPerms === null) {
            self::$userPerms = $_SESSION['_perms'] ?? [];
        }
        return in_array($perm, self::$userPerms, true);
    }

    /** Web guard: redirect to login/denied. */
    public static function require(string $perm): void
    {
        if (!Auth::check()) {
            header('Location: ' . self::baseUrl() . '/login');
            exit;
        }
        if (!self::can($perm)) {
            Audit::log('ACCESS_DENIED', null, ['permission' => $perm]);
            http_response_code(403);
            (new \App\Controllers\PageController())->forbidden();
            exit;
        }
    }

    /** API guard: accepts session user OR institution API key; emits 401/403 JSON. */
    public static function requireApi(string $perm): void
    {
        if (ApiAuth::institution() !== null) {
            if (!ApiAuth::can($perm)) {
                Response::json(['error' => 'Forbidden — permission denied: ' . $perm], 403);
            }
            return;
        }
        if (!Auth::check()) {
            Response::json(['error' => 'Unauthenticated'], 401);
        }
        if (!self::can($perm)) {
            Response::json(['error' => 'Forbidden — missing permission: ' . $perm], 403);
        }
    }

    /** Base URL auto-detected from the front controller's location. */
    public static function baseUrl(): string
    {
        return rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
    }
}

/**
 * API-key authentication for machine-to-machine ingestion/inquiry (Category 2 real-time).
 * Expects header: X-FNCRB-Key
 */
final class ApiAuth
{
    public static ?array $institution = null;

    public static function check(): bool
    {
        return self::institution() !== null;
    }

    public static function institution(): ?array
    {
        if (self::$institution !== null) return self::$institution;
        $key = $_SERVER['HTTP_X_FNCRB_KEY'] ?? '';
        if ($key === '') return null;
        $stmt = Database::pdo()->prepare(
            "SELECT * FROM institutions
             WHERE api_key_hash = ? AND status = 'ACTIVE'"
        );
        $stmt->execute([hash('sha256', $key)]);
        $inst = $stmt->fetch();
        if ($inst) {
            self::$institution = $inst;
        }
        return $inst;
    }

    public static function can(string $perm): bool
    {
        return self::institution() !== null
            && in_array($perm, ['loan.report', 'inquiry.perform', 'incident.report'], true);
    }
}
