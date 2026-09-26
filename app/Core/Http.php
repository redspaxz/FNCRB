<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Request/response helpers shared by web controllers: endpoints accept either
 * a regular form post (redirect + flash message) or JSON (fetch clients).
 */
final class Http
{
    private static ?array $json = null;

    public static function isJson(): bool
    {
        return str_contains((string)($_SERVER['CONTENT_TYPE'] ?? ''), 'application/json');
    }

    /** Request input: decoded JSON body or $_POST. */
    public static function input(): array
    {
        if (!self::isJson()) return $_POST;
        if (self::$json === null) {
            $d = json_decode((string)file_get_contents('php://input'), true);
            self::$json = is_array($d) ? $d : [];
        }
        return self::$json;
    }

    /** CSRF check matching the request style. */
    public static function verifyCsrf(): void
    {
        self::isJson() ? Csrf::verifyJson() : Csrf::verify();
    }

    /** Finish a state-changing action: JSON response, or flash + redirect. */
    public static function done(bool $ok, string $message, string $redirect, array $data = [], int $failStatus = 422): never
    {
        if (self::isJson()) {
            Response::json($ok ? ['ok' => true, 'message' => $message] + $data
                               : ['error' => ['code' => 'REQUEST_FAILED', 'message' => $message]], $ok ? 200 : $failStatus);
        }
        Flash::set($ok ? 'success' : 'danger', $message);
        header('Location: ' . Rbac::baseUrl() . $redirect);
        exit;
    }
}

final class Flash
{
    public static function set(string $type, string $message): void
    {
        $_SESSION['_flash'][] = ['type' => $type, 'message' => $message];
    }

    /** @return array<int, array{type:string, message:string}> */
    public static function take(): array
    {
        $f = $_SESSION['_flash'] ?? [];
        unset($_SESSION['_flash']);
        return $f;
    }
}
