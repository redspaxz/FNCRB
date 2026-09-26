<?php
declare(strict_types=1);

namespace App\Core;

/** Cached access to config/config.php using dotted keys ("security.lockout_minutes"). */
final class Config
{
    private static ?array $cfg = null;

    public static function all(): array
    {
        return self::$cfg ??= require dirname(__DIR__, 2) . '/config/config.php';
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        $v = self::all();
        foreach (explode('.', $key) as $k) {
            if (!is_array($v) || !array_key_exists($k, $v)) return $default;
            $v = $v[$k];
        }
        return $v;
    }
}
