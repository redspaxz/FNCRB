<?php
declare(strict_types=1);

namespace App\Core;

/** Input validation helpers (server-side, whitelist-driven — OWASP A03). */
final class Validator
{
    public static function string(array $src, string $key, int $max = 255, int $min = 1): ?string
    {
        $raw = $src[$key] ?? '';
        if (!is_scalar($raw)) return null;
        $v = trim((string)$raw);
        if (mb_strlen($v) < $min || mb_strlen($v) > $max) return null;
        return $v;
    }

    public static function email(array $src, string $key): ?string
    {
        $raw = $src[$key] ?? '';
        if (!is_scalar($raw)) return null;
        $v = filter_var(trim((string)$raw), FILTER_VALIDATE_EMAIL);
        return $v ? strtolower($v) : null;
    }

    public static function int(array $src, string $key, ?int $min = null, ?int $max = null): ?int
    {
        $raw = $src[$key] ?? null;
        if (is_float($raw) && floor($raw) === $raw) $raw = (int)$raw;
        if (!is_int($raw) && !is_string($raw)) return null;
        $v = filter_var($raw, FILTER_VALIDATE_INT);
        if ($v === false) return null;
        if ($min !== null && $v < $min) return null;
        if ($max !== null && $v > $max) return null;
        return $v;
    }

    public static function decimal(array $src, string $key, float $min, float $max): ?float
    {
        $raw = $src[$key] ?? null;
        if (!is_int($raw) && !is_float($raw) && !(is_string($raw) && is_numeric(trim($raw)))) return null;
        $v = (float)$raw;
        return ($v < $min || $v > $max) ? null : $v;
    }

    public static function date(array $src, string $key): ?string
    {
        $raw = $src[$key] ?? '';
        if (!is_string($raw)) return null;
        $v = trim($raw);
        $d = \DateTime::createFromFormat('!Y-m-d', $v);
        return ($d && $d->format('Y-m-d') === $v) ? $v : null;
    }

    public static function enum(array $src, string $key, array $allowed): ?string
    {
        $raw = $src[$key] ?? '';
        if (!is_scalar($raw)) return null;
        $v = (string)$raw;
        return in_array($v, $allowed, true) ? $v : null;
    }

    /** Registry identifier (CNI / NIU / coop id / master ref / contract ref): conservative charset. */
    public static function identifier(array $src, string $key, int $max = 50): ?string
    {
        $v = self::string($src, $key, $max);
        return ($v !== null && preg_match('#^[A-Za-z0-9][A-Za-z0-9/_.\-]*$#', $v)) ? $v : null;
    }

    public static function password(string $pw): bool
    {
        return strlen($pw) >= (int)Config::get('security.min_password_len', 10)
            && preg_match('/[A-Z]/', $pw) && preg_match('/[a-z]/', $pw)
            && preg_match('/\d/', $pw);
    }

    /** Policy-compliant random password (for generated one-time credentials). */
    public static function generatePassword(): string
    {
        return 'Fn-' . bin2hex(random_bytes(6)) . 'Q' . random_int(10, 99);
    }
}
