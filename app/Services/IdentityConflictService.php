<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Audit;
use App\Core\Database;

/**
 * Bureau decisions on identity conflicts raised by ReconciliationService.
 * ACCEPTED → the reviewer confirms the submitted identity is the matched
 *            registry borrower (name variant, typo); the parked loan/incident
 *            is re-ingested onto that credit file.
 * REJECTED → the submission is refused; the institution must correct its data.
 */
final class IdentityConflictService
{
    public static ?string $error = null;

    public static function resolve(int $id, string $decision, string $note, int $userId, ?int $borrowerId = null): bool
    {
        self::$error = null;
        $stmt = Database::pdo()->prepare("SELECT * FROM identity_conflicts WHERE id = ? AND status = 'OPEN'");
        $stmt->execute([$id]);
        $c = $stmt->fetch();
        if (!$c) { self::$error = 'Conflict not found or already decided.'; return false; }
        if (mb_strlen(trim($note)) < 3) { self::$error = 'A decision note is required.'; return false; }

        $result = null;
        if ($decision === 'ACCEPTED') {
            $target = $borrowerId ?? (int)$c['matched_borrower_id'];
            $ctx = $c['context'] ? json_decode((string)$c['context'], true) : [];
            $record = is_array($ctx['record'] ?? null) ? $ctx['record'] : null;
            if ($record && $c['institution_id'] !== null) {
                $record['borrower'] = ['id' => $target];
                if (($ctx['kind'] ?? '') === 'loan') {
                    $loanId = IngestionService::upsertLoan($record, (int)$c['institution_id'], (string)$c['source']);
                    if (!$loanId) { self::$error = 'Re-ingestion failed: ' . implode('; ', IngestionService::$errors); return false; }
                    $result = ['loan_id' => $loanId];
                } elseif (($ctx['kind'] ?? '') === 'incident') {
                    unset($record['borrower']);
                    $record['borrower_id'] = $target;
                    $incId = IncidentService::report($record, (int)$c['institution_id'], (string)$c['source']);
                    if (!$incId) { self::$error = 'Re-ingestion failed: ' . IncidentService::$error; return false; }
                    $result = ['incident_id' => $incId];
                }
            }
        }

        Database::pdo()->prepare(
            "UPDATE identity_conflicts SET status = ?, decided_by = ?, decided_at = NOW(), decision_note = ? WHERE id = ?"
        )->execute([$decision, $userId, mb_substr(trim($note), 0, 255), $id]);
        Audit::log('IDENTITY_CONFLICT_' . $decision, ['type' => 'identity_conflict', 'id' => $id], ['note' => $note] + ($result ?? []));
        return true;
    }
}
