<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Symmetric encryption for secrets at rest (TOTP seeds): AES-256-GCM with a
 * key derived from app.secret. Values carry an "enc:v1:" prefix; anything
 * without it is treated as legacy plaintext so existing enrolments keep working.
 */
final class Crypto
{
    private const PREFIX = 'enc:v1:';

    private static function key(): string
    {
        return hash('sha256', 'fncrb-at-rest|' . (string)Config::get('app.secret'), true);
    }

    public static function encrypt(string $plain): string
    {
        $iv = random_bytes(12);
        $tag = '';
        $ct = openssl_encrypt($plain, 'aes-256-gcm', self::key(), OPENSSL_RAW_DATA, $iv, $tag);
        return self::PREFIX . base64_encode($iv . $tag . $ct);
    }

    public static function decrypt(string $stored): ?string
    {
        if (!str_starts_with($stored, self::PREFIX)) return $stored; // legacy plaintext
        $raw = base64_decode(substr($stored, strlen(self::PREFIX)), true);
        if ($raw === false || strlen($raw) < 29) return null;
        $plain = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', self::key(), OPENSSL_RAW_DATA,
            substr($raw, 0, 12), substr($raw, 12, 16));
        return $plain === false ? null : $plain;
    }

    public static function isDefaultSecret(): bool
    {
        $s = (string)Config::get('app.secret');
        return strlen($s) < 32 || str_starts_with($s, 'CHANGE-ME') || str_starts_with($s, 'GENERATE-');
    }
}
