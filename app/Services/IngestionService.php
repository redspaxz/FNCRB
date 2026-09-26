<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Config;
use App\Core\Database;
use App\Core\Validator;

/**
 * Module 1 — Data Ingestion & Centralization:
 * batch/API/manual ingestion of COBAC-standardized loan records with upsert
 * semantics, strict per-record validation, month-over-month history, guarantor
 * capture and protection of dispute corrections.
 */
final class IngestionService
{
    public const LOAN_TYPES  = ['CONSUMER','MORTGAGE','BUSINESS','MICRO','PROJECT','OVERDRAFT','LEASE'];
    public const LOAN_STATUS = ['ACTIVE','SETTLED','WRITTEN_OFF','RESTRUCTURED'];
    /** A corrected field may not be re-submitted with the disputed value for this many days. */
    private const CORRECTION_PROTECT_DAYS = 180;

    /** @var string[] errors of the last record */
    public static array $errors = [];

    /**
     * Validate + normalize one record. Returns the normalized record or null
     * (errors in self::$errors).
     */
    public static function validate(array $d): ?array
    {
        $e = [];
        // accept a flat full_name/identifiers layout as the borrower block
        $borrower = $d['borrower'] ?? null;
        if ($borrower === null && isset($d['full_name'])) {
            $borrower = array_intersect_key($d, array_flip(['full_name', 'cni_number', 'niu', 'coop_member_id', 'date_of_birth', 'gender', 'type', 'phone', 'region']));
        }
        if (!is_array($borrower)) $e[] = 'borrower block required';

        $n = [];
        $n['contract_ref'] = Validator::identifier($d, 'contract_ref', 50) ?? ($e[] = 'contract_ref required (A-Z, 0-9, / _ . -; max 50)') && null;
        $n['loan_type'] = Validator::enum($d, 'loan_type', self::LOAN_TYPES) ?? ($e[] = 'loan_type invalid') && null;
        $n['principal_xaf'] = Validator::int($d, 'principal_xaf', 1) ?? ($e[] = 'principal_xaf must be a positive integer') && null;
        $n['outstanding_xaf'] = Validator::int($d, 'outstanding_xaf', 0) ?? ($e[] = 'outstanding_xaf must be an integer ≥ 0') && null;
        $n['monthly_payment_xaf'] = self::optInt($d, 'monthly_payment_xaf', 0, PHP_INT_MAX, $e);
        $n['interest_rate_pct'] = array_key_exists('interest_rate_pct', $d) && $d['interest_rate_pct'] !== '' && $d['interest_rate_pct'] !== null
            ? (Validator::decimal($d, 'interest_rate_pct', 0, 999.999) ?? ($e[] = 'interest_rate_pct must be between 0 and 999.999') && 0.0)
            : 0.0;
        $n['start_date'] = Validator::date($d, 'start_date') ?? ($e[] = 'start_date must be YYYY-MM-DD') && null;
        $n['maturity_date'] = Validator::date($d, 'maturity_date') ?? ($e[] = 'maturity_date must be YYYY-MM-DD') && null;
        $n['reported_at'] = Validator::date($d, 'reported_at') ?? ($e[] = 'reported_at must be YYYY-MM-DD') && null;
        $n['instalments_total'] = self::optInt($d, 'instalments_total', 0, 65535, $e);
        $n['instalments_past_due'] = self::optInt($d, 'instalments_past_due', 0, 65535, $e);
        $n['days_past_due'] = self::optInt($d, 'days_past_due', 0, 36500, $e);
        if (array_key_exists('status', $d) && $d['status'] !== null && $d['status'] !== '') {
            $n['status'] = Validator::enum($d, 'status', self::LOAN_STATUS) ?? ($e[] = 'status invalid') && null;
        } else {
            $n['status'] = 'ACTIVE';
        }

        if (!$e) {
            if ($n['maturity_date'] < $n['start_date']) $e[] = 'maturity_date is before start_date';
            if ($n['reported_at'] > date('Y-m-d', strtotime('+1 day'))) $e[] = 'reported_at is in the future';
            if ($n['instalments_past_due'] > $n['instalments_total'] && $n['instalments_total'] > 0) $e[] = 'instalments_past_due exceeds instalments_total';
            if ($n['status'] === 'SETTLED' && $n['outstanding_xaf'] !== 0) $e[] = 'a SETTLED loan must have outstanding_xaf = 0';
        }

        $guarantors = null;
        if (isset($d['guarantors'])) {
            if (!is_array($d['guarantors']) || !array_is_list($d['guarantors']) || count($d['guarantors']) > 50) {
                $e[] = 'guarantors must be a list (max 50)';
            } else {
                $guarantors = [];
                foreach ($d['guarantors'] as $i => $g) {
                    $gn = is_array($g) ? Validator::string($g, 'full_name', 200) : null;
                    $ga = is_array($g) ? Validator::int($g, 'guarantee_xaf', 0) : null;
                    if ($gn === null || $ga === null) { $e[] = "guarantors[$i] requires full_name and guarantee_xaf"; continue; }
                    $guarantors[] = [
                        'full_name' => $gn, 'guarantee_xaf' => $ga,
                        'cni_number' => Validator::identifier($g, 'cni_number', 30),
                        'solidarity_group' => Validator::string($g, 'solidarity_group', 60),
                    ];
                }
            }
        }

        if ($e) { self::$errors = $e; return null; }
        $n['borrower'] = $borrower;
        $n['guarantors'] = $guarantors;
        return $n;
    }

    private static function optInt(array $d, string $k, int $min, int $max, array &$e): int
    {
        if (!array_key_exists($k, $d) || $d[$k] === null || $d[$k] === '') return 0;
        $v = Validator::int($d, $k, $min, $max);
        if ($v === null) { $e[] = "$k must be an integer between $min and $max"; return 0; }
        return $v;
    }

    /**
     * Upsert a single standardized loan record. Returns the loan id, or null
     * (reasons in self::$errors — including IDENTITY_CONFLICT and CORRECTION_REVERT).
     */
    public static function upsertLoan(array $d, int $institutionId, string $source = 'API'): ?int
    {
        self::$errors = [];
        $n = self::validate($d);
        if (!$n) return null;

        $record = $d;
        unset($record['borrower']);
        $borrower = ReconciliationService::resolve($n['borrower'], $institutionId, $source,
            ['kind' => 'loan', 'contract_ref' => $n['contract_ref'], 'record' => $record]);
        if (!$borrower) { self::$errors[] = ReconciliationService::$error ?? 'borrower could not be resolved'; return null; }

        $pdo = Database::pdo();
        $existing = self::existing($institutionId, $n['contract_ref']);

        if ($existing && ($revert = self::revertsCorrection((int)$existing['id'], $n))) {
            self::$errors[] = "CORRECTION_REVERT: $revert was corrected after a consumer dispute; correct it at source before re-reporting";
            return null;
        }
        if ($existing && $existing['reported_at'] > $n['reported_at']) {
            self::$errors[] = "stale record: registry already holds reporting period {$existing['reported_at']} for this contract";
            return null;
        }

        $cls = ClassificationService::classify($n['days_past_due'], $n['status']);
        $prov = ClassificationService::provision($cls, $n['outstanding_xaf']);

        $pdo->beginTransaction();
        try {
            if ($existing) self::snapshot($existing);
            $pdo->prepare(
                "INSERT INTO loans (institution_id, borrower_id, contract_ref, loan_type, currency, principal_xaf,
                    outstanding_xaf, monthly_payment_xaf, interest_rate_pct, start_date, maturity_date,
                    instalments_total, instalments_past_due, days_past_due, cobac_class, provision_xaf,
                    status, reported_at, source)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
                 ON DUPLICATE KEY UPDATE
                    borrower_id=VALUES(borrower_id), loan_type=VALUES(loan_type), principal_xaf=VALUES(principal_xaf),
                    outstanding_xaf=VALUES(outstanding_xaf), monthly_payment_xaf=VALUES(monthly_payment_xaf),
                    interest_rate_pct=VALUES(interest_rate_pct), start_date=VALUES(start_date),
                    maturity_date=VALUES(maturity_date), instalments_total=VALUES(instalments_total),
                    instalments_past_due=VALUES(instalments_past_due), days_past_due=VALUES(days_past_due),
                    cobac_class=VALUES(cobac_class), provision_xaf=VALUES(provision_xaf), status=VALUES(status),
                    reported_at=VALUES(reported_at), source=VALUES(source)"
            )->execute([
                $institutionId, $borrower['id'], $n['contract_ref'], $n['loan_type'], 'XAF',
                $n['principal_xaf'], $n['outstanding_xaf'], $n['monthly_payment_xaf'], $n['interest_rate_pct'],
                $n['start_date'], $n['maturity_date'], $n['instalments_total'], $n['instalments_past_due'],
                $n['days_past_due'], $cls, $prov, $n['status'], $n['reported_at'], $source,
            ]);
            $loanId = $existing ? (int)$existing['id'] : (int)$pdo->lastInsertId();
            if ($n['guarantors'] !== null) self::replaceGuarantors($loanId, $n['guarantors']);
            $pdo->commit();
            return $loanId;
        } catch (\PDOException $e) {
            $pdo->rollBack();
            error_log('FNCRB ingestion: ' . $e->getMessage());
            self::$errors[] = 'storage error (' . $e->getCode() . ')';
            return null;
        }
    }

    /**
     * Ingest a batch; each record is isolated (one bad record never aborts the
     * batch). Logs the submission for data-quality metrics.
     * @return array{accepted:int, rejected:int, errors:array}
     */
    public static function ingestBatch(array $records, int $institutionId, string $source): array
    {
        $accepted = 0;
        $errors = [];
        foreach ($records as $i => $rec) {
            if (!is_array($rec)) { $errors[] = ['index' => $i, 'messages' => ['record must be an object']]; continue; }
            $id = self::upsertLoan($rec, $institutionId, $source);
            if ($id) $accepted++;
            else $errors[] = ['index' => $i, 'contract_ref' => is_scalar($rec['contract_ref'] ?? null) ? (string)$rec['contract_ref'] : null, 'messages' => self::$errors];
        }
        DataQualityService::logSubmission($institutionId, $source, count($records), $accepted, count($errors));
        return ['accepted' => $accepted, 'rejected' => count($errors), 'errors' => $errors];
    }

    private static function existing(int $instId, string $ref): ?array
    {
        $stmt = Database::pdo()->prepare("SELECT * FROM loans WHERE institution_id = ? AND contract_ref = ?");
        $stmt->execute([$instId, $ref]);
        return $stmt->fetch() ?: null;
    }

    /** Keep the prior reporting state (payment history) before it is overwritten. */
    private static function snapshot(array $l): void
    {
        Database::pdo()->prepare(
            "INSERT INTO loan_history (loan_id, reported_at, outstanding_xaf, days_past_due, instalments_past_due, cobac_class, status)
             VALUES (?,?,?,?,?,?,?)
             ON DUPLICATE KEY UPDATE outstanding_xaf=VALUES(outstanding_xaf), days_past_due=VALUES(days_past_due),
                instalments_past_due=VALUES(instalments_past_due), cobac_class=VALUES(cobac_class), status=VALUES(status)"
        )->execute([$l['id'], $l['reported_at'], $l['outstanding_xaf'], $l['days_past_due'],
            $l['instalments_past_due'], $l['cobac_class'], $l['status']]);
    }

    /** Returns the field name when the submission re-imposes a value removed by a dispute correction. */
    private static function revertsCorrection(int $loanId, array $n): ?string
    {
        $stmt = Database::pdo()->prepare(
            "SELECT field, old_value FROM data_corrections
             WHERE entity = 'loans' AND entity_id = ? AND created_at > (NOW() - INTERVAL " . self::CORRECTION_PROTECT_DAYS . " DAY)
             ORDER BY id DESC"
        );
        $stmt->execute([$loanId]);
        foreach ($stmt->fetchAll() as $c) {
            if (array_key_exists($c['field'], $n) && (string)$n[$c['field']] === (string)$c['old_value']) {
                return $c['field'];
            }
        }
        return null;
    }

    private static function replaceGuarantors(int $loanId, array $guarantors): void
    {
        $pdo = Database::pdo();
        $pdo->prepare("DELETE FROM guarantors WHERE loan_id = ?")->execute([$loanId]);
        $ins = $pdo->prepare(
            "INSERT INTO guarantors (borrower_id, full_name, cni_number, loan_id, guarantee_xaf, solidarity_group) VALUES (?,?,?,?,?,?)"
        );
        foreach ($guarantors as $g) {
            $gb = $g['cni_number'] ? ReconciliationService::findByIdentity($g['cni_number'], null, null) : null;
            if ($gb && !ReconciliationService::namesMatch($g['full_name'], (string)$gb['full_name'])) $gb = null;
            $ins->execute([$gb['id'] ?? null, $g['full_name'], $g['cni_number'], $loanId, $g['guarantee_xaf'], $g['solidarity_group']]);
        }
    }

    /** Reclassify an institution's open portfolio (compliance batch job). */
    public static function reclassifyPortfolio(?int $institutionId = null): int
    {
        $sql = "SELECT id, days_past_due, outstanding_xaf, status, cobac_class, provision_xaf FROM loans
                WHERE status IN " . ClassificationService::OPEN_SQL;
        $params = [];
        if ($institutionId) { $sql .= " AND institution_id=?"; $params[] = $institutionId; }
        $stmt = Database::pdo()->prepare($sql);
        $stmt->execute($params);
        $n = 0;
        $upd = Database::pdo()->prepare("UPDATE loans SET cobac_class=?, provision_xaf=? WHERE id=?");
        foreach ($stmt->fetchAll() as $l) {
            $cls = ClassificationService::classify((int)$l['days_past_due'], $l['status']);
            $prov = ClassificationService::provision($cls, (int)$l['outstanding_xaf']);
            if ($cls !== $l['cobac_class'] || $prov !== (int)$l['provision_xaf']) {
                $upd->execute([$cls, $prov, $l['id']]);
                $n++;
            }
        }
        return $n;
    }

    public static function maxBatch(): int
    {
        return (int)Config::get('api.max_records_per_batch', 5000);
    }
}
