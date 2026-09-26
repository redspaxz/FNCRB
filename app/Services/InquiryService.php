<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Audit;
use App\Core\Config;
use App\Core\Database;

/**
 * Module 5 — Consent-gated inquiry workflow:
 * NO credit check may run without a valid, recorded, unrevoked borrower consent
 * (COBAC + Law 2010/012). A consent is recorded with its signature date, the
 * capturing user and — for physical consents — the scanned signed form
 * (SHA-256 fingerprinted); digital consents carry the e-signature reference.
 */
final class InquiryService
{
    public const CONSENT_TYPES = ['DIGITAL', 'PHYSICAL'];
    private const EVIDENCE_TYPES = ['application/pdf' => 'pdf', 'image/jpeg' => 'jpg', 'image/png' => 'png'];

    /** @var string|null last error */
    public static ?string $error = null;

    /**
     * Record (or reuse) a consent. A consent reference is unique per institution:
     * re-presenting the same reference for the same borrower reuses the active
     * consent; presenting it for another borrower is refused.
     *
     * @param array $evidence optional ['sha256' => hex, 'path' => relative path]
     */
    public static function recordConsent(
        int $borrowerId, int $institutionId, string $type, string $ref, string $signedAt,
        ?int $userId, array $evidence = [], string $scope = 'CREDIT_CHECK'
    ): ?int {
        self::$error = null;
        $ref = trim($ref);
        if (!in_array($type, self::CONSENT_TYPES, true)) { self::$error = 'invalid consent type (DIGITAL|PHYSICAL)'; return null; }
        if ($ref === '' || mb_strlen($ref) > 80) { self::$error = 'consent reference required (max 80 chars)'; return null; }
        $d = \DateTime::createFromFormat('!Y-m-d', $signedAt);
        if (!$d || $d->format('Y-m-d') !== $signedAt) { self::$error = 'consent signature date must be YYYY-MM-DD'; return null; }
        $maxAge = (int)Config::get('security.consent_max_age_days', 30);
        if ($signedAt > date('Y-m-d')) { self::$error = 'consent signature date is in the future'; return null; }
        if ($signedAt < date('Y-m-d', strtotime("-$maxAge days"))) { self::$error = "consent was signed more than $maxAge days ago — obtain a fresh consent"; return null; }
        if ($type === 'PHYSICAL' && empty($evidence['sha256'])) { self::$error = 'a physical consent requires the scanned signed form as evidence'; return null; }

        $pdo = Database::pdo();
        $stmt = $pdo->prepare("SELECT * FROM consents WHERE institution_id = ? AND consent_ref = ? ORDER BY id DESC LIMIT 1");
        $stmt->execute([$institutionId, $ref]);
        if ($prior = $stmt->fetch()) {
            if ((int)$prior['borrower_id'] !== $borrowerId) {
                self::$error = 'this consent reference is already registered for another borrower';
                Audit::log('CONSENT_REF_REUSE_BLOCKED', ['type' => 'consent', 'id' => $prior['id']], ['borrower_id' => $borrowerId]);
                return null;
            }
            if ($prior['revoked_at'] === null && strtotime((string)$prior['expires_at']) > time()) {
                return (int)$prior['id'];
            }
            self::$error = 'this consent reference was revoked or has expired — a new consent is required';
            return null;
        }

        $ttl = (int)Config::get('security.consent_ttl_days', 90);
        $pdo->prepare(
            "INSERT INTO consents (borrower_id, institution_id, consent_type, consent_ref, scope, signed_at, granted_at, expires_at,
                                   evidence_sha256, evidence_path, captured_by)
             VALUES (?,?,?,?,?,?, NOW(), DATE_ADD(?, INTERVAL ? DAY), ?,?,?)"
        )->execute([$borrowerId, $institutionId, $type, $ref, $scope, $signedAt, $signedAt, $ttl,
            $evidence['sha256'] ?? null, $evidence['path'] ?? null, $userId]);
        $id = (int)$pdo->lastInsertId();
        Audit::log('CONSENT_RECORDED', ['type' => 'consent', 'id' => $id], [
            'borrower_id' => $borrowerId, 'consent_type' => $type, 'signed_at' => $signedAt,
            'evidence_sha256' => $evidence['sha256'] ?? null,
        ]);
        return $id;
    }

    /** Store an uploaded consent form (validated type/size) outside the web root. */
    public static function storeEvidence(?array $file): ?array
    {
        self::$error = null;
        if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) return [];
        if ($file['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) { self::$error = 'evidence upload failed'; return null; }
        $max = (int)Config::get('security.consent_evidence_max_bytes', 5242880);
        if ($file['size'] > $max) { self::$error = 'evidence file too large (max ' . round($max / 1048576) . ' MB)'; return null; }
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
        if (!isset(self::EVIDENCE_TYPES[$mime])) { self::$error = 'evidence must be a PDF, JPEG or PNG'; return null; }
        $sha = hash_file('sha256', $file['tmp_name']);
        $dir = dirname(__DIR__, 2) . '/storage/consents';
        if (!is_dir($dir)) mkdir($dir, 0770, true);
        $rel = 'consents/' . $sha . '.' . self::EVIDENCE_TYPES[$mime];
        $dest = dirname(__DIR__, 2) . '/storage/' . $rel;
        if (!is_file($dest) && !move_uploaded_file($file['tmp_name'], $dest)) { self::$error = 'could not store evidence'; return null; }
        return ['sha256' => $sha, 'path' => $rel];
    }

    public static function revokeConsent(int $consentId, int $institutionId, int $userId, string $reason): bool
    {
        $stmt = Database::pdo()->prepare(
            "UPDATE consents SET revoked_at = NOW(), revoked_by = ?, revoke_reason = ?
             WHERE id = ? AND institution_id = ? AND revoked_at IS NULL"
        );
        $stmt->execute([$userId, mb_substr($reason, 0, 255), $consentId, $institutionId]);
        if ($stmt->rowCount() !== 1) { self::$error = 'consent not found, not yours, or already revoked'; return false; }
        Audit::log('CONSENT_REVOKED', ['type' => 'consent', 'id' => $consentId], ['reason' => $reason]);
        return true;
    }

    public static function validateConsent(int $borrowerId, int $institutionId, ?int $consentId): ?array
    {
        $base = "SELECT * FROM consents WHERE borrower_id=? AND institution_id=? AND expires_at > NOW() AND revoked_at IS NULL";
        if ($consentId === null) {
            $stmt = Database::pdo()->prepare("$base ORDER BY id DESC LIMIT 1");
            $stmt->execute([$borrowerId, $institutionId]);
        } else {
            $stmt = Database::pdo()->prepare("$base AND id=?");
            $stmt->execute([$borrowerId, $institutionId, $consentId]);
        }
        $c = $stmt->fetch();
        if (!$c) {
            self::$error = 'No valid borrower consent on record — inquiry refused (COBAC sanction risk).';
            return null;
        }
        return $c;
    }

    /** Full credit report: exposure + history + guarantees + collateral + incidents + score. */
    public static function runInquiry(
        int $borrowerId,
        int $institutionId,
        ?int $userId,
        string $channel = 'WEB',
        string $purpose = '',
        ?int $consentId = null,
        int $declaredIncome = 0
    ): ?array {
        $consent = self::validateConsent($borrowerId, $institutionId, $consentId);
        if (!$consent) {
            Audit::log('INQUIRY_REFUSED', ['type' => 'borrower', 'id' => $borrowerId], ['reason' => 'no consent', 'channel' => $channel]);
            return null;
        }

        $exposure  = ExposureService::forBorrower($borrowerId);
        $guarantees = ExposureService::guaranteesOf($borrowerId);
        $history = ExposureService::history($borrowerId);

        $stmt = Database::pdo()->prepare(
            "SELECT c.*, i.code AS institution_code FROM collateral c
             JOIN loans l ON l.id = c.loan_id
             JOIN institutions i ON i.id = c.institution_id
             WHERE l.borrower_id = ? AND c.status = 'REGISTERED'"
        );
        $stmt->execute([$borrowerId]);
        $collateral = $stmt->fetchAll();

        $stmt = Database::pdo()->prepare(
            "SELECT pi.*, i.code AS institution_code FROM payment_incidents pi
             JOIN institutions i ON i.id = pi.institution_id
             WHERE pi.borrower_id = ? ORDER BY pi.incident_date DESC"
        );
        $stmt->execute([$borrowerId]);
        $incidents = $stmt->fetchAll();

        $score = ScoringService::score($borrowerId, $declaredIncome);

        Database::pdo()->prepare(
            "INSERT INTO inquiry_logs (institution_id, user_id, borrower_id, consent_id, channel, purpose)
             VALUES (?,?,?,?,?,?)"
        )->execute([$institutionId, $userId, $borrowerId, $consent['id'], $channel, mb_substr($purpose, 0, 255)]);

        Audit::log('INQUIRY', ['type' => 'borrower', 'id' => $borrowerId], [
            'consent_id' => (int)$consent['id'], 'channel' => $channel,
        ]);

        return [
            'consent'    => $consent,
            'exposure'   => $exposure,
            'history'    => $history,
            'guarantees' => $guarantees,
            'collateral' => $collateral,
            'incidents'  => $incidents,
            'score'      => $score,
            'generated_at' => date('c'),
        ];
    }
}
