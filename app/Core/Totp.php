<?php
declare(strict_types=1);

namespace App\Core;

/**
 * RFC 6238 TOTP (30s window, SHA-1, 6 digits) — pure PHP, no dependencies.
 * Secrets are stored encrypted (Crypto) in users.totp_secret; the last accepted
 * time step is kept in users.totp_last_step so a code cannot be replayed.
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
        return self::codeForStep($base32Secret, intdiv($time ?? time(), 30));
    }

    private static function codeForStep(string $base32Secret, int $step): string
    {
        $key = self::base32Decode($base32Secret);
        if ($key === false || strlen($key) < 10) return '';
        return self::hotp($key, $step);
    }

    /**
     * Verify with ±1 step tolerance. Returns the matched time step, or null.
     * Steps at or below $lastStep are refused (replay protection).
     */
    public static function verifyStep(string $base32Secret, string $code, ?int $lastStep = null, ?int $now = null): ?int
    {
        if (!preg_match('/^\d{6}$/', $code)) return null;
        $cur = intdiv($now ?? time(), 30);
        foreach ([0, -1, 1] as $drift) {
            $step = $cur + $drift;
            if ($lastStep !== null && $step <= $lastStep) continue;
            $expected = self::codeForStep($base32Secret, $step);
            if ($expected !== '' && hash_equals($expected, $code)) return $step;
        }
        return null;
    }

    public static function verify(string $base32Secret, string $code): bool
    {
        return self::verifyStep($base32Secret, $code) !== null;
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
