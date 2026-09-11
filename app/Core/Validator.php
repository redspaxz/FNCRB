<?php
declare(strict_types=1);

namespace App\Core;

/** Input validation helpers (server-side, whitelist-driven — OWASP A03). */
final class Validator
{
    public static function string(array $src, string $key, int $max = 255, int $min = 1): ?string
    {
        $v = trim((string)($src[$key] ?? ''));
        if (mb_strlen($v) < $min || mb_strlen($v) > $max) return null;
        return $v;
    }

    public static function email(array $src, string $key): ?string
    {
        $v = filter_var(trim((string)($src[$key] ?? '')), FILTER_VALIDATE_EMAIL);
        return $v ?: null;
    }

    public static function int(array $src, string $key, ?int $min = null, ?int $max = null): ?int
    {
        $v = filter_var($src[$key] ?? null, FILTER_VALIDATE_INT);
        if ($v === false) return null;
        if ($min !== null && $v < $min) return null;
        if ($max !== null && $v > $max) return null;
        return $v;
    }

    public static function date(array $src, string $key): ?string
    {
        $v = trim((string)($src[$key] ?? ''));
        $d = \DateTime::createFromFormat('Y-m-d', $v);
        return ($d && $d->format('Y-m-d') === $v) ? $v : null;
    }

    public static function enum(array $src, string $key, array $allowed): ?string
    {
        $v = (string)($src[$key] ?? '');
        return in_array($v, $allowed, true) ? $v : null;
    }

    public static function password(string $pw): bool
    {
        $c = require dirname(__DIR__, 2) . '/config/config.php';
        return strlen($pw) >= $c['security']['min_password_len']
            && preg_match('/[A-Z]/', $pw) && preg_match('/[a-z]/', $pw)
            && preg_match('/\d/', $pw);
    }
}
