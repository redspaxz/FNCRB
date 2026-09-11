<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Audit;
use App\Core\Database;

/**
 * Module 5 — Consent-gated inquiry workflow:
 * NO credit check may run without a valid, recorded borrower consent (COBAC + privacy law).
 */
final class InquiryService
{
    /** @var string|null last error */
    public static ?string $error = null;

    public static function recordConsent(int $borrowerId, int $institutionId, string $type, string $ref, string $scope = 'CREDIT_CHECK'): ?int
    {
        if (!in_array($type, ['DIGITAL', 'PHYSICAL'], true)) { self::$error = 'invalid consent type'; return null; }
        $stmt = Database::pdo()->prepare(
            "INSERT INTO consents (borrower_id, institution_id, consent_type, consent_ref, scope, granted_at, expires_at)
             VALUES (?,?,?,?,?, NOW(), DATE_ADD(NOW(), INTERVAL 90 DAY))"
        );
        $stmt->execute([$borrowerId, $institutionId, $type, $ref, $scope]);
        $id = (int)Database::pdo()->lastInsertId();
        Audit::log('CONSENT_RECORDED', ['type' => 'consent', 'id' => $id], ['borrower_id' => $borrowerId]);
        return $id;
    }

    public static function validateConsent(int $borrowerId, int $institutionId, ?int $consentId): ?array
    {
        if ($consentId === null) {
            // allow any active consent held by this institution for this borrower
            $stmt = Database::pdo()->prepare(
                "SELECT * FROM consents WHERE borrower_id=? AND institution_id=? AND expires_at > NOW() ORDER BY id DESC LIMIT 1"
            );
            $stmt->execute([$borrowerId, $institutionId]);
        } else {
            $stmt = Database::pdo()->prepare(
                "SELECT * FROM consents WHERE id=? AND borrower_id=? AND institution_id=? AND expires_at > NOW()"
            );
            $stmt->execute([$consentId, $borrowerId, $institutionId]);
        }
        $c = $stmt->fetch();
        if (!$c) {
            self::$error = 'No valid borrower consent on record — inquiry refused (COBAC sanction risk).';
            return null;
        }
        return $c;
    }

    /** Full credit report: exposure + guarantees + collateral + incidents + score. */
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
            Audit::log('INQUIRY_REFUSED', ['type' => 'borrower', 'id' => $borrowerId], ['reason' => 'no consent']);
            return null;
        }

        $exposure  = ExposureService::forBorrower($borrowerId);
        $guarantees = ExposureService::guaranteesOf($borrowerId);

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

        $stmt = Database::pdo()->prepare(
            "INSERT INTO inquiry_logs (institution_id, user_id, borrower_id, consent_id, channel, purpose)
             VALUES (?,?,?,?,?,?)"
        );
        $stmt->execute([$institutionId, $userId, $borrowerId, $consent['id'], $channel, mb_substr($purpose, 0, 255)]);

        Audit::log('INQUIRY', ['type' => 'borrower', 'id' => $borrowerId], [
            'consent_id' => (int)$consent['id'], 'channel' => $channel,
        ]);

        return [
            'consent'    => $consent,
            'exposure'   => $exposure,
            'guarantees' => $guarantees,
            'collateral' => $collateral,
            'incidents'  => $incidents,
            'score'      => $score,
            'generated_at' => date('c'),
        ];
    }
}
