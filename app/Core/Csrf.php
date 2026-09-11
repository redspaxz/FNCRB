<?php
declare(strict_types=1);

namespace App\Core;

final class Csrf
{
    public static function token(): string
    {
        if (empty($_SESSION['_csrf'])) {
            $_SESSION['_csrf'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['_csrf'];
    }

    public static function field(): string
    {
        return '<input type="hidden" name="_csrf" value="' . self::token() . '">';
    }

    public static function verify(): void
    {
        $sent = $_POST['_csrf'] ?? '';
        if (empty($_SESSION['_csrf']) || !hash_equals($_SESSION['_csrf'], (string)$sent)) {
            Audit::log('CSRF_REJECTED', null, ['uri' => $_SERVER['REQUEST_URI'] ?? '']);
            http_response_code(419);
            (new \App\Controllers\PageController())->error(419, 'CSRF token invalid or expired. Please retry the action.');
            exit;
        }
    }

    public static function verifyJson(): void
    {
        $sent = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($_POST['_csrf'] ?? '');
        if (empty($_SESSION['_csrf']) || !hash_equals($_SESSION['_csrf'], (string)$sent)) {
            Response::json(['error' => 'CSRF token invalid'], 419);
        }
    }
}
