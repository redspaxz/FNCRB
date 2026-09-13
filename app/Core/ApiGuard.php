<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Hardened machine-channel gate for /api/v1/*.
 *
 * A request must carry:
 *   X-FNCRB-Key         institution API key (stored SHA-256)
 *   X-FNCRB-Timestamp   unix seconds, ±5 min window
 *   X-FNCRB-Nonce       unique random hex per request (replay protection)
 *   X-FNCRB-Signature   hex HMAC-SHA256(key, "<timestamp>.<nonce>.<raw body>")
 *
 * Plus optional per-institution IP allow-list (institutions.ip_allowlist)
 * and a rolling rate limit (max requests/minute via nonce timestamps).
 */
final class ApiGuard
{
    public const MAX_REQUESTS_PER_MIN = 60;
    private const CLOCK_SKEW_SECONDS = 300;

    /** @var array|null authenticated institution row */
    private static ?array $inst = null;

    /** Validate everything; on failure emit a typed error and exit. */
    public static function authenticate(): array
    {
        $key = trim((string)($_SERVER['HTTP_X_FNCRB_KEY'] ?? ''));
        $ts  = (string)($_SERVER['HTTP_X_FNCRB_TIMESTAMP'] ?? '');
        $nonce = (string)($_SERVER['HTTP_X_FNCRB_NONCE'] ?? '');
        $sig  = (string)($_SERVER['HTTP_X_FNCRB_SIGNATURE'] ?? '');
        $body = (string)file_get_contents('php://input');

        if ($key === '')      self::fail('AUTH_MISSING_KEY', 'X-FNCRB-Key header is required.', 401);
        if ($ts === '' || !ctype_digit($ts) || abs(time() - (int)$ts) > self::CLOCK_SKEW_SECONDS)
            self::fail('AUTH_BAD_TIMESTAMP', 'X-FNCRB-Timestamp missing or outside the ±300s window.', 401);
        if (!preg_match('/^[a-f0-9]{16,64}$/i', $nonce))
            self::fail('AUTH_BAD_NONCE', 'X-FNCRB-Nonce must be 16-64 hex characters.', 401);
        if ($sig === '')      self::fail('AUTH_MISSING_SIGNATURE', 'X-FNCRB-Signature header is required.', 401);

        // institution lookup by key hash
        $stmt = Database::pdo()->prepare("SELECT * FROM institutions WHERE api_key_hash = ? AND status = 'ACTIVE'");
        $stmt->execute([hash('sha256', $key)]);
        $inst = $stmt->fetch();
        if (!$inst) self::fail('AUTH_INVALID_KEY', 'Unknown, inactive or revoked API key.', 401);

        // HMAC verification (constant-time)
        $expected = hash_hmac('sha256', $ts . '.' . $nonce . '.' . $body, $key);
        if (!hash_equals($expected, strtolower($sig)))
            self::fail('AUTH_BAD_SIGNATURE', 'Request signature verification failed.', 401);

        // IP allow-list (when configured)
        if (!empty($inst['ip_allowlist'])) {
            $ip = $_SERVER['REMOTE_ADDR'] ?? '';
            $allowed = array_filter(array_map('trim', preg_split('/[\s,;]+/', (string)$inst['ip_allowlist'])));
            if ($ip !== '' && !in_array($ip, $allowed, true))
                self::fail('AUTH_IP_BLOCKED', "Client IP not in institution allow-list.", 403);
        }

        // rate limit: accepted nonces in the last 60s
        $pdo = Database::pdo();
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM api_nonces WHERE institution_id = ? AND created_at > (NOW() - INTERVAL 60 SECOND)");
        $stmt->execute([$inst['id']]);
        if ((int)$stmt->fetchColumn() >= self::MAX_REQUESTS_PER_MIN)
            self::fail('RATE_LIMITED', 'More than ' . self::MAX_REQUESTS_PER_MIN . ' requests/minute — throttled.', 429);

        // replay protection: insert nonce (unique PK = reject duplicates)
        try {
            $pdo->prepare("INSERT INTO api_nonces (nonce, institution_id, ip_address) VALUES (?,?,?)")
                ->execute([$nonce, $inst['id'], $_SERVER['REMOTE_ADDR'] ?? null]);
        } catch (\PDOException $e) {
            self::fail('AUTH_REPLAYED_NONCE', 'Nonce already used — possible replay attack.', 409);
        }

        // housekeeping: prune nonces older than the skew window
        if (random_int(1, 50) === 1) {
            $pdo->exec("DELETE FROM api_nonces WHERE created_at < (NOW() - INTERVAL 1 HOUR)");
        }

        Audit::log('API_AUTHENTICATED', ['type' => 'institution', 'id' => $inst['id']], ['ip' => $_SERVER['REMOTE_ADDR'] ?? null]);
        self::$inst = $inst;
        return $inst;
    }

    public static function institution(): ?array
    {
        return self::$inst;
    }

    /** Typed error envelope: {"error":{"code":..,"message":..}} */
    public static function fail(string $code, string $message, int $status): never
    {
        Audit::log('API_REJECTED', null, ['code' => $code, 'ip' => $_SERVER['REMOTE_ADDR'] ?? null]);
        Response::json(['error' => ['code' => $code, 'message' => $message]], $status);
    }
}
