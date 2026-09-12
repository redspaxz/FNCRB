<?php
declare(strict_types=1);

namespace App\Core;

/**
 * RFC 6238 TOTP (30s window, SHA-1, 6 digits) — pure PHP, no dependencies.
 * Secrets are stored base32-encoded in users.totp_secret.
 */
final class Totp
{
    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    public static function generateSecret(int $length = 32): string
    {
        $chars = [];
        for ($i = 0; $i < $length; $i++) $chars[] = self::ALPHABET[random_int(0, 31)];
        return implode('', $chars);
    }

    public static function code(string $base32Secret, ?int $time = null): string
    {
        $key = self::base32Decode($base32Secret);
        if ($key === false || strlen($key) < 10) return '';
        $slice = intdiv($time ?? time(), 30);
        return self::hotp($key, $slice);
    }

    /** Verify with +/- 1 window tolerance; rate-limit-safe constant-time compare. */
    public static function verify(string $base32Secret, string $code): bool
    {
        if (!preg_match('/^\d{6}$/', $code)) return false;
        foreach ([0, -1, 1] as $drift) {
            $expected = self::code($base32Secret, time() + $drift * 30);
            if ($expected !== '' && hash_equals($expected, $code)) return true;
        }
        return false;
    }

    /** otpauth:// URI for authenticator app enrollment (QR via any external generator). */
    public static function otpauthUri(string $base32Secret, string $account, string $issuer = 'FINACREB'): string
    {
        return 'otpauth://totp/' . rawurlencode($issuer) . ':' . rawurlencode($account)
             . '?secret=' . $base32Secret . '&issuer=' . rawurlencode($issuer)
             . '&algorithm=SHA1&digits=6&period=30';
    }

    private static function hotp(string $key, int $counter): string
    {
        $binCounter = pack('N*', 0) . pack('N*', $counter);
        $hash = hash_hmac('sha1', $binCounter, $key, true);
        $offset = ord($hash[19]) & 0x0F;
        $value = ((ord($hash[$offset]) & 0x7F) << 24)
               | ((ord($hash[$offset + 1]) & 0xFF) << 16)
               | ((ord($hash[$offset + 2]) & 0xFF) << 8)
               | (ord($hash[$offset + 3]) & 0xFF);
        return str_pad((string)($value % 1_000_000), 6, '0', STR_PAD_LEFT);
    }

    private static function base32Decode(string $b32): string|false
    {
        $b32 = strtoupper(preg_replace('/[^A-Z2-7]/i', '', $b32));
        $bits = '';
        foreach (str_split($b32) as $c) {
            $pos = strpos(self::ALPHABET, $c);
            if ($pos === false) return false;
            $bits .= str_pad(decbin($pos), 5, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 8) as $byte) {
            if (strlen($byte) === 8) $out .= chr((int)bindec($byte));
        }
        return $out;
    }
}
