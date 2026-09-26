<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Audit;
use App\Core\Database;
use App\Core\Validator;

/**
 * Module 2 — Payment incidents (CNEF / Centrale des Incidents de Paiement):
 * reporting by the institution that holds the instrument, and regularization
 * (resolution) by that same institution. Only unresolved incidents weigh on
 * the score; history is kept.
 */
final class IncidentService
{
    public const TYPES = ['BOUNCED_CHEQUE', 'DEFAULTED_NOTE', 'UNAUTHORIZED_OVERDRAFT', 'FRAUD_INSTRUMENT'];

    /** @var string|null last error */
    public static ?string $error = null;

    /**
     * @param array $d borrower_id | borrower_identifier | borrower{...}, incident_type, instrument_ref, amount_xaf, incident_date
     */
    public static function report(array $d, int $institutionId, string $source): ?int
    {
        self::$error = null;
        $type = Validator::enum($d, 'incident_type', self::TYPES);
        $ref = Validator::identifier($d, 'instrument_ref', 60);
        $amt = Validator::int($d, 'amount_xaf', 1);
        $date = Validator::date($d, 'incident_date');
        if (!$type || !$ref || !$amt || !$date) {
            self::$error = 'incident_type, instrument_ref, amount_xaf (> 0) and incident_date (YYYY-MM-DD) are required.';
            return null;
        }
        if ($date > date('Y-m-d')) { self::$error = 'incident_date is in the future.'; return null; }

        $borrower = null;
        if (!empty($d['borrower_identifier']) && is_string($d['borrower_identifier'])) {
            $borrower = ReconciliationService::lookup($d['borrower_identifier']);
        } elseif (!empty($d['borrower_id'])) {
            $borrower = ReconciliationService::byId((int)$d['borrower_id']);
        } elseif (isset($d['borrower']) && is_array($d['borrower'])) {
            $record = $d;
            unset($record['borrower']);
            $borrower = ReconciliationService::resolve($d['borrower'], $institutionId, $source,
                ['kind' => 'incident', 'instrument_ref' => $ref, 'record' => $record]);
            if (!$borrower) { self::$error = ReconciliationService::$error; return null; }
        }
        if (!$borrower) { self::$error = 'Borrower not found in the registry.'; return null; }

        $dup = Database::pdo()->prepare("SELECT id FROM payment_incidents WHERE institution_id = ? AND instrument_ref = ? AND incident_type = ?");
        $dup->execute([$institutionId, $ref, $type]);
        if ($dup->fetchColumn()) { self::$error = 'This instrument has already been reported by your institution.'; return null; }

        Database::pdo()->prepare(
            "INSERT INTO payment_incidents (institution_id, borrower_id, incident_type, instrument_ref, amount_xaf, incident_date)
             VALUES (?,?,?,?,?,?)"
        )->execute([$institutionId, $borrower['id'], $type, $ref, $amt, $date]);
        $id = (int)Database::pdo()->lastInsertId();
        Audit::log('DATA_WRITE', ['type' => 'incident', 'id' => $id], ['action' => 'CIP report', 'source' => $source]);
        return $id;
    }

    /** Regularization by the reporting institution. */
    public static function resolve(int $id, int $institutionId, string $resolvedAt, string $note, ?int $userId): bool
    {
        self::$error = null;
        $d = \DateTime::createFromFormat('!Y-m-d', $resolvedAt);
        if (!$d || $d->format('Y-m-d') !== $resolvedAt || $resolvedAt > date('Y-m-d')) { self::$error = 'Resolution date must be a past date (YYYY-MM-DD).'; return false; }
        if (mb_strlen(trim($note)) < 3) { self::$error = 'A regularization note is required.'; return false; }
        $stmt = Database::pdo()->prepare(
            "UPDATE payment_incidents SET resolved = 1, resolved_at = ?, resolution_note = ?, resolved_by = ?
             WHERE id = ? AND institution_id = ? AND resolved = 0 AND incident_date <= ?"
        );
        $stmt->execute([$resolvedAt, mb_substr(trim($note), 0, 255), $userId, $id, $institutionId, $resolvedAt]);
        if ($stmt->rowCount() !== 1) { self::$error = 'Incident not found, not reported by your institution, already resolved, or resolution precedes the incident.'; return false; }
        Audit::log('INCIDENT_RESOLVED', ['type' => 'incident', 'id' => $id], ['resolved_at' => $resolvedAt]);
        return true;
    }
}
