<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Audit;
use App\Core\Database;

/**
 * Module 1 — Identity Reconciliation.
 *
 * Matches incoming borrower identities against the registry by CNI, NIU and
 * cooperative ID. A match is only accepted when the name (and date of birth,
 * when both are known) agree; otherwise the record is parked in the
 * identity_conflicts queue for bureau review instead of silently attaching a
 * loan to someone else's credit file. Identifiers that point to two different
 * registry borrowers are also treated as a conflict.
 */
final class ReconciliationService
{
    private const ID_FIELDS = ['cni_number', 'niu', 'coop_member_id'];
    private const STOP_WORDS = ['sarl', 'sa', 'sas', 'ltd', 'plc', 'ets', 'cie', 'de', 'du', 'des', 'la', 'le', 'les', 'et', 'the', 'and', 'mr', 'mme', 'mrs', 'dr'];

    /** @var string|null last error */
    public static ?string $error = null;
    /** @var int|null id of the conflict recorded by the last resolve() */
    public static ?int $conflictId = null;

    // ------------------------------------------------------------------ lookup

    /** Follow the reconciliation pointer to the surviving master row. */
    public static function master(?array $row): ?array
    {
        $guard = 0;
        while ($row && $row['dup_of_id'] !== null && $guard++ < 10) {
            $stmt = Database::pdo()->prepare("SELECT * FROM borrowers WHERE id = ?");
            $stmt->execute([$row['dup_of_id']]);
            $row = $stmt->fetch() ?: null;
        }
        return $row;
    }

    public static function byId(int $id): ?array
    {
        $stmt = Database::pdo()->prepare("SELECT * FROM borrowers WHERE id = ?");
        $stmt->execute([$id]);
        return self::master($stmt->fetch() ?: null);
    }

    /** Exact lookup by any registry identifier (master ref, CNI, NIU, coop id). */
    public static function lookup(string $identifier): ?array
    {
        $identifier = trim($identifier);
        if ($identifier === '') return null;
        foreach (['master_ref', 'cni_number', 'niu', 'coop_member_id'] as $col) {
            $stmt = Database::pdo()->prepare("SELECT * FROM borrowers WHERE $col = ? ORDER BY dup_of_id IS NULL DESC LIMIT 1");
            $stmt->execute([$identifier]);
            if ($row = $stmt->fetch()) return self::master($row);
        }
        return null;
    }

    public static function findByIdentity(?string $cni, ?string $niu, ?string $coopId): ?array
    {
        foreach ([['cni_number', $cni], ['niu', $niu], ['coop_member_id', $coopId]] as [$col, $val]) {
            if ($val === null || $val === '') continue;
            $stmt = Database::pdo()->prepare("SELECT * FROM borrowers WHERE $col = ? LIMIT 1");
            $stmt->execute([$val]);
            if ($row = $stmt->fetch()) return self::master($row);
        }
        return null;
    }

    /**
     * SQL predicate restricting borrowers (alias $b) to those an institution has
     * a legitimate relationship with: it created the record, reports a credit
     * on it, or holds an active consent. Returns [sql, params].
     */
    public static function scopeSql(string $b, int $instId): array
    {
        return [
            "($b.created_by_inst_id = ?
              OR EXISTS (SELECT 1 FROM loans sl WHERE sl.borrower_id = $b.id AND sl.institution_id = ?)
              OR EXISTS (SELECT 1 FROM consents sc WHERE sc.borrower_id = $b.id AND sc.institution_id = ?
                         AND sc.revoked_at IS NULL AND sc.expires_at > NOW()))",
            [$instId, $instId, $instId],
        ];
    }

    public static function institutionCanSee(int $borrowerId, int $instId): bool
    {
        [$sql, $p] = self::scopeSql('b', $instId);
        $stmt = Database::pdo()->prepare("SELECT 1 FROM borrowers b WHERE b.id = ? AND $sql");
        $stmt->execute(array_merge([$borrowerId], $p));
        return (bool)$stmt->fetchColumn();
    }

    // ------------------------------------------------------------------ resolve / create

    /**
     * Resolve a submitted borrower block to a registry borrower, creating one
     * when no identifier matches. Returns null on validation error or conflict
     * (see self::$error / self::$conflictId).
     *
     * @param array $b borrower block: id|master_ref | full_name, cni_number, niu, coop_member_id, date_of_birth, gender, type, phone, region
     */
    public static function resolve(array $b, ?int $instId, string $source, array $context = []): ?array
    {
        self::$error = null;
        self::$conflictId = null;

        if (!empty($b['id']) || !empty($b['master_ref'])) {
            $row = !empty($b['id']) ? self::byId((int)$b['id']) : self::lookup((string)$b['master_ref']);
            if (!$row) { self::$error = 'unknown registry borrower reference'; return null; }
            return $row;
        }

        $name = trim((string)($b['full_name'] ?? ''));
        if ($name === '' || mb_strlen($name) > 200) { self::$error = 'borrower.full_name required (max 200)'; return null; }
        $ids = [];
        foreach (self::ID_FIELDS as $f) {
            $v = trim((string)($b[$f] ?? ''));
            if ($v !== '') {
                if (!preg_match('#^[A-Za-z0-9][A-Za-z0-9/_.\-]{0,39}$#', $v)) { self::$error = "borrower.$f has an invalid format"; return null; }
                $ids[$f] = $v;
            }
        }
        if (!$ids) { self::$error = 'at least one borrower identifier (cni_number, niu or coop_member_id) is required'; return null; }
        $dob = isset($b['date_of_birth']) && $b['date_of_birth'] !== '' ? (string)$b['date_of_birth'] : null;
        if ($dob !== null && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dob)) { self::$error = 'borrower.date_of_birth must be YYYY-MM-DD'; return null; }

        $matches = [];
        foreach ($ids as $f => $v) {
            $stmt = Database::pdo()->prepare("SELECT * FROM borrowers WHERE $f = ? LIMIT 1");
            $stmt->execute([$v]);
            if ($row = self::master($stmt->fetch() ?: null)) $matches[(int)$row['id']] = $row;
        }

        if (count($matches) > 1) {
            return self::conflict($instId, $source, $b, (int)array_key_first($matches), 'MULTIPLE_MATCH',
                'identifiers point to different registry borrowers (' . implode(', ', array_column($matches, 'master_ref')) . ')', $context);
        }
        if (count($matches) === 1) {
            $row = reset($matches);
            if (!self::namesMatch($name, (string)$row['full_name'])) {
                return self::conflict($instId, $source, $b, (int)$row['id'], 'NAME_MISMATCH',
                    'identifier matches ' . $row['master_ref'] . ' but the name differs', $context);
            }
            if ($dob !== null && $row['date_of_birth'] !== null && $row['date_of_birth'] !== $dob) {
                return self::conflict($instId, $source, $b, (int)$row['id'], 'DOB_MISMATCH',
                    'identifier matches ' . $row['master_ref'] . ' but the date of birth differs', $context);
            }
            self::enrich($row, $b, $ids);
            return self::byId((int)$row['id']);
        }

        return self::create($b + ['full_name' => $name], $ids, $instId);
    }

    /** Web-form registration (no silent attach — caller checks for existing identity first). */
    public static function findOrCreate(array $d, ?int $instId = null): ?array
    {
        return self::resolve($d, $instId, 'MANUAL');
    }

    private static function create(array $b, array $ids, ?int $instId): ?array
    {
        $pdo = Database::pdo();
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $ref = 'FNB' . str_pad((string)random_int(0, 999999999), 9, '0', STR_PAD_LEFT);
            try {
                $pdo->prepare(
                    "INSERT INTO borrowers (master_ref, full_name, date_of_birth, gender, type, cni_number, niu, coop_member_id, phone, region, created_by_inst_id)
                     VALUES (?,?,?,?,?,?,?,?,?,?,?)"
                )->execute([
                    $ref, trim((string)$b['full_name']), $b['date_of_birth'] ?? null ?: null,
                    in_array($b['gender'] ?? null, ['M', 'F'], true) ? $b['gender'] : null,
                    in_array($b['type'] ?? null, ['INDIVIDUAL', 'CORPORATE'], true) ? $b['type'] : 'INDIVIDUAL',
                    $ids['cni_number'] ?? null, $ids['niu'] ?? null, $ids['coop_member_id'] ?? null,
                    self::clip($b['phone'] ?? null, 25), self::clip($b['region'] ?? null, 60), $instId,
                ]);
                return self::byId((int)$pdo->lastInsertId());
            } catch (\PDOException $e) {
                if ($e->getCode() !== '23000') throw $e;
                // either master_ref collision (retry) or a concurrent insert of the same identity
                $existing = self::findByIdentity($ids['cni_number'] ?? null, $ids['niu'] ?? null, $ids['coop_member_id'] ?? null);
                if ($existing) return self::namesMatch((string)$b['full_name'], (string)$existing['full_name']) ? $existing : null;
            }
        }
        self::$error = 'could not allocate a registry reference';
        return null;
    }

    /** Fill blank identity fields on an existing borrower (never overwrite). */
    private static function enrich(array $row, array $b, array $ids): void
    {
        $sets = [];
        $params = [];
        foreach ($ids as $f => $v) {
            if ($row[$f] === null && !self::findByIdentity($f === 'cni_number' ? $v : null, $f === 'niu' ? $v : null, $f === 'coop_member_id' ? $v : null)) {
                $sets[] = "$f = ?"; $params[] = $v;
            }
        }
        foreach (['date_of_birth' => 10, 'phone' => 25, 'region' => 60] as $f => $len) {
            if ($row[$f] === null && !empty($b[$f])) { $sets[] = "$f = ?"; $params[] = self::clip((string)$b[$f], $len); }
        }
        if ($row['gender'] === null && in_array($b['gender'] ?? null, ['M', 'F'], true)) { $sets[] = 'gender = ?'; $params[] = $b['gender']; }
        if (!$sets) return;
        $params[] = $row['id'];
        try {
            Database::pdo()->prepare("UPDATE borrowers SET " . implode(', ', $sets) . " WHERE id = ?")->execute($params);
        } catch (\PDOException $e) {
            if ($e->getCode() !== '23000') throw $e; // identifier claimed concurrently — leave as is
        }
    }

    private static function conflict(?int $instId, string $source, array $b, int $matchedId, string $type, string $msg, array $context): ?array
    {
        $stmt = Database::pdo()->prepare(
            "INSERT INTO identity_conflicts (institution_id, source, conflict_type, matched_borrower_id, submitted, context, message)
             VALUES (?,?,?,?,?,?,?)"
        );
        $stmt->execute([$instId, $source, $type, $matchedId, json_encode($b, JSON_UNESCAPED_UNICODE),
            $context ? json_encode($context, JSON_UNESCAPED_UNICODE) : null, $msg]);
        self::$conflictId = (int)Database::pdo()->lastInsertId();
        self::$error = "IDENTITY_CONFLICT #" . self::$conflictId . ": $msg — queued for bureau review";
        Audit::log('IDENTITY_CONFLICT', ['type' => 'identity_conflict', 'id' => self::$conflictId],
            ['conflict_type' => $type, 'matched_borrower_id' => $matchedId, 'source' => $source]);
        return null;
    }

    // ------------------------------------------------------------------ name matching

    public static function normalizeName(string $name): array
    {
        $s = mb_strtolower($name, 'UTF-8');
        $s = strtr($s, [
            'à' => 'a', 'á' => 'a', 'â' => 'a', 'ä' => 'a', 'ã' => 'a', 'ç' => 'c', 'è' => 'e', 'é' => 'e',
            'ê' => 'e', 'ë' => 'e', 'ì' => 'i', 'í' => 'i', 'î' => 'i', 'ï' => 'i', 'ñ' => 'n', 'ò' => 'o',
            'ó' => 'o', 'ô' => 'o', 'ö' => 'o', 'ù' => 'u', 'ú' => 'u', 'û' => 'u', 'ü' => 'u', 'ÿ' => 'y',
            'œ' => 'oe', 'æ' => 'ae',
        ]);
        $tokens = preg_split('/[^a-z0-9]+/', $s, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $tokens = array_values(array_filter($tokens, fn($t) => !in_array($t, self::STOP_WORDS, true)));
        sort($tokens);
        return $tokens;
    }

    /** Tolerant same-person check: word order, accents, initials and 1-char typos are accepted. */
    public static function namesMatch(string $a, string $b): bool
    {
        $ta = self::normalizeName($a);
        $tb = self::normalizeName($b);
        if (!$ta || !$tb) return false;
        if ($ta === $tb) return true;
        similar_text(implode(' ', $ta), implode(' ', $tb), $pct);
        if ($pct >= 85) return true;
        [$short, $long] = count($ta) <= count($tb) ? [$ta, $tb] : [$tb, $ta];
        $fullMatches = 0;
        foreach ($short as $t) {
            $hit = false;
            foreach ($long as $u) {
                if ($t === $u || (strlen($t) >= 5 && levenshtein($t, $u) <= 1)) { $hit = true; $fullMatches++; break; }
                if ((strlen($t) === 1 && $u[0] === $t) || (strlen($u) === 1 && $t[0] === $u)) { $hit = true; break; }
            }
            if (!$hit) return false;
        }
        return $fullMatches >= 1;
    }

    // ------------------------------------------------------------------ merge

    /**
     * Merge a duplicate into a master: every credit-file reference is repointed
     * and the duplicate row is kept (pointer) for traceability.
     */
    public static function merge(int $duplicateId, int $masterId, int $userId, string $reason = ''): bool
    {
        self::$error = null;
        if ($duplicateId === $masterId) { self::$error = 'cannot merge a borrower into itself'; return false; }
        $dup = self::byId($duplicateId);
        $master = self::byId($masterId);
        if (!$dup || !$master || (int)$dup['id'] !== $duplicateId || (int)$master['id'] !== $masterId) {
            self::$error = 'both borrowers must exist and be active (not already merged)';
            return false;
        }
        $pdo = Database::pdo();
        $pdo->beginTransaction();
        try {
            foreach ([['loans', 'borrower_id'], ['payment_incidents', 'borrower_id'], ['consents', 'borrower_id'],
                      ['inquiry_logs', 'borrower_id'], ['guarantors', 'borrower_id'], ['disputes', 'borrower_id'],
                      ['score_snapshots', 'borrower_id']] as [$t, $c]) {
                $pdo->prepare("UPDATE `$t` SET `$c` = ? WHERE `$c` = ?")->execute([$masterId, $duplicateId]);
            }
            $pdo->prepare("UPDATE borrowers SET dup_of_id = ? WHERE id = ?")->execute([$masterId, $duplicateId]);
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            self::$error = 'merge failed: ' . $e->getMessage();
            return false;
        }
        Audit::log('BORROWER_MERGED', ['type' => 'borrower', 'id' => $masterId],
            ['duplicate_id' => $duplicateId, 'duplicate_ref' => $dup['master_ref'], 'master_ref' => $master['master_ref'], 'reason' => $reason, 'by' => $userId]);
        return true;
    }

    private static function clip(mixed $v, int $len): ?string
    {
        if ($v === null || $v === '' || !is_scalar($v)) return null;
        return mb_substr(trim((string)$v), 0, $len);
    }
}
