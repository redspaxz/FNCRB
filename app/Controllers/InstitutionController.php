<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Csrf;
use App\Core\Database;
use App\Core\Flash;
use App\Core\Rbac;
use App\Core\Validator;
use App\Core\View;
use App\Services\IdentityConflictService;
use App\Services\ReconciliationService;

/**
 * Bureau administration of reporting institutions: onboarding, suspension /
 * revocation (ends all user sessions and API access), prudential equity,
 * machine-API IP allow-list and API key rotation.
 */
final class InstitutionController
{
    private const CATEGORIES = ['CAT1', 'CAT2', 'CAT3', 'BANK'];

    public function index(): void
    {
        Rbac::require('institutions.manage');
        $rows = Database::pdo()->query(
            "SELECT i.*, (SELECT COUNT(*) FROM users u WHERE u.institution_id = i.id) AS users,
                    (SELECT COUNT(*) FROM loans l WHERE l.institution_id = i.id) AS loans
             FROM institutions i ORDER BY i.category = 'REGULATOR' DESC, i.code"
        )->fetchAll();
        View::render('institutions/index', ['rows' => $rows, 'newKey' => $_SESSION['_new_api_key'] ?? null]);
        unset($_SESSION['_new_api_key']);
    }

    public function create(): void
    {
        Rbac::require('institutions.manage');
        View::render('institutions/form', ['inst' => null, 'error' => null, 'categories' => self::CATEGORIES]);
    }

    public function edit(): void
    {
        Rbac::require('institutions.manage');
        $inst = $this->find(Validator::int($_GET, 'id', 1));
        View::render('institutions/form', ['inst' => $inst, 'error' => null, 'categories' => self::CATEGORIES]);
    }

    private function find(?int $id): array
    {
        $stmt = Database::pdo()->prepare("SELECT * FROM institutions WHERE id = ? AND category != 'REGULATOR'");
        $stmt->execute([$id ?? 0]);
        $inst = $stmt->fetch();
        if (!$inst) { http_response_code(404); (new PageController())->notFound(); exit; }
        return $inst;
    }

    /** @return array{0: ?array, 1: ?string} */
    private function validated(bool $isNew): array
    {
        $d = [
            'name' => Validator::string($_POST, 'name', 200),
            'net_equity_xaf' => Validator::int($_POST, 'net_equity_xaf', 0),
            'legal_form' => Validator::string($_POST, 'legal_form', 100, 0) ?: null,
            'rccm_number' => Validator::string($_POST, 'rccm_number', 50, 0) ?: null,
            'niu' => Validator::string($_POST, 'niu', 50, 0) ?: null,
            'head_office' => Validator::string($_POST, 'head_office', 200, 0) ?: null,
            'ip_allowlist' => trim((string)($_POST['ip_allowlist'] ?? '')) ?: null,
        ];
        if ($isNew) {
            $d['code'] = strtoupper(trim((string)($_POST['code'] ?? '')));
            $d['category'] = Validator::enum($_POST, 'category', self::CATEGORIES);
            if (!preg_match('/^[A-Z0-9-]{2,20}$/', $d['code'])) return [null, 'Code must be 2–20 characters (A–Z, 0–9, -).'];
            if (!$d['category']) return [null, 'Category is required.'];
        } else {
            $d['status'] = Validator::enum($_POST, 'status', ['ACTIVE', 'SUSPENDED', 'REVOKED']);
            if (!$d['status']) return [null, 'Status is required.'];
        }
        if (!$d['name']) return [null, 'Name is required.'];
        if ($d['net_equity_xaf'] === null) return [null, 'Net equity must be an integer ≥ 0 (XAF).'];
        if ($d['ip_allowlist'] !== null) {
            $ips = array_filter(array_map('trim', preg_split('/[\s,;]+/', $d['ip_allowlist'])));
            foreach ($ips as $ip) {
                if (!filter_var($ip, FILTER_VALIDATE_IP)) return [null, "Invalid IP address in allow-list: $ip"];
            }
            $d['ip_allowlist'] = implode(',', $ips) ?: null;
        }
        return [$d, null];
    }

    public function store(): void
    {
        Rbac::require('institutions.manage');
        Csrf::verify();
        [$d, $err] = $this->validated(true);
        if ($err) { View::render('institutions/form', ['inst' => $_POST, 'error' => $err, 'categories' => self::CATEGORIES, 'isNew' => true]); return; }
        $dup = Database::pdo()->prepare("SELECT 1 FROM institutions WHERE code = ?");
        $dup->execute([$d['code']]);
        if ($dup->fetchColumn()) { View::render('institutions/form', ['inst' => $_POST, 'error' => 'Code already in use.', 'categories' => self::CATEGORIES, 'isNew' => true]); return; }
        Database::pdo()->prepare(
            "INSERT INTO institutions (code, name, category, legal_form, rccm_number, niu, head_office, net_equity_xaf, ip_allowlist)
             VALUES (?,?,?,?,?,?,?,?,?)"
        )->execute([$d['code'], $d['name'], $d['category'], $d['legal_form'], $d['rccm_number'], $d['niu'], $d['head_office'], $d['net_equity_xaf'], $d['ip_allowlist']]);
        $id = (int)Database::pdo()->lastInsertId();
        Audit::log('INSTITUTION_CREATED', ['type' => 'institution', 'id' => $id], ['code' => $d['code'], 'category' => $d['category']]);
        Flash::set('success', "Institution {$d['code']} onboarded. Create its administrator under Users, and issue an API key if it uses the machine channel.");
        header('Location: ' . Rbac::baseUrl() . '/institutions');
    }

    public function update(): void
    {
        Rbac::require('institutions.manage');
        Csrf::verify();
        $inst = $this->find(Validator::int($_POST, 'id', 1));
        [$d, $err] = $this->validated(false);
        if ($err) { View::render('institutions/form', ['inst' => array_merge($inst, $_POST), 'error' => $err, 'categories' => self::CATEGORIES]); return; }
        Database::pdo()->prepare(
            "UPDATE institutions SET name=?, legal_form=?, rccm_number=?, niu=?, head_office=?, net_equity_xaf=?, ip_allowlist=?, status=? WHERE id=?"
        )->execute([$d['name'], $d['legal_form'], $d['rccm_number'], $d['niu'], $d['head_office'], $d['net_equity_xaf'], $d['ip_allowlist'], $d['status'], $inst['id']]);
        $changes = [];
        foreach (['name', 'net_equity_xaf', 'ip_allowlist', 'status'] as $k) {
            if ((string)$inst[$k] !== (string)$d[$k]) $changes[$k] = ['from' => $inst[$k], 'to' => $d[$k]];
        }
        Audit::log($d['status'] !== $inst['status'] ? 'INSTITUTION_STATUS_CHANGED' : 'INSTITUTION_UPDATED',
            ['type' => 'institution', 'id' => $inst['id']], $changes);
        Flash::set('success', "Institution {$inst['code']} updated." . ($d['status'] !== 'ACTIVE' ? ' Its users and API key are blocked immediately.' : ''));
        header('Location: ' . Rbac::baseUrl() . '/institutions');
    }

    /** Issue a new API key (shown once); the previous key stops working immediately. */
    public function rotateKey(): void
    {
        Rbac::require('institutions.manage');
        Csrf::verify();
        $inst = $this->find(Validator::int($_POST, 'id', 1));
        $key = 'fncrb_' . bin2hex(random_bytes(24));
        Database::pdo()->prepare("UPDATE institutions SET api_key_hash = ? WHERE id = ?")->execute([hash('sha256', $key), $inst['id']]);
        Audit::log('API_KEY_ROTATED', ['type' => 'institution', 'id' => $inst['id']]);
        $_SESSION['_new_api_key'] = ['code' => $inst['code'], 'key' => $key];
        header('Location: ' . Rbac::baseUrl() . '/institutions');
    }
}

/**
 * Identity reconciliation queue (bureau): review conflicts raised during
 * ingestion, accept (same person — re-ingest onto the matched file) or reject,
 * and merge duplicate borrower records.
 */
final class ReconciliationController
{
    public function index(): void
    {
        Rbac::require('borrower.reconcile');
        $status = Validator::enum($_GET, 'status', ['OPEN', 'ACCEPTED', 'REJECTED']) ?? 'OPEN';
        $stmt = Database::pdo()->prepare(
            "SELECT c.*, i.code AS inst_code, b.master_ref, b.full_name AS matched_name, b.date_of_birth AS matched_dob,
                    b.cni_number AS matched_cni, b.niu AS matched_niu
             FROM identity_conflicts c
             LEFT JOIN institutions i ON i.id = c.institution_id
             LEFT JOIN borrowers b ON b.id = c.matched_borrower_id
             WHERE c.status = ? ORDER BY c.id DESC LIMIT 200"
        );
        $stmt->execute([$status]);
        View::render('reconciliation/index', ['rows' => $stmt->fetchAll(), 'status' => $status]);
    }

    public function resolve(): void
    {
        Rbac::require('borrower.reconcile');
        Csrf::verify();
        $id = Validator::int($_POST, 'id', 1);
        $decision = Validator::enum($_POST, 'decision', ['ACCEPTED', 'REJECTED']);
        $note = Validator::string($_POST, 'note', 255) ?? '';
        $targetRef = Validator::identifier($_POST, 'borrower_ref', 40);
        $target = $targetRef ? ReconciliationService::lookup($targetRef) : null;
        if ($targetRef && !$target) {
            Flash::set('danger', 'Unknown target registry reference.');
            header('Location: ' . Rbac::baseUrl() . '/reconciliation');
            return;
        }
        $ok = $id && $decision && IdentityConflictService::resolve($id, $decision, $note, (int)\App\Core\Auth::id(), $target ? (int)$target['id'] : null);
        Flash::set($ok ? 'success' : 'danger', $ok ? "Conflict #$id $decision." : (IdentityConflictService::$error ?? 'Invalid request.'));
        header('Location: ' . Rbac::baseUrl() . '/reconciliation');
    }

    public function merge(): void
    {
        Rbac::require('borrower.reconcile');
        Csrf::verify();
        $dupRef = Validator::identifier($_POST, 'duplicate_ref', 40);
        $masterRef = Validator::identifier($_POST, 'master_ref', 40);
        $reason = Validator::string($_POST, 'reason', 255) ?? '';
        $dup = $dupRef ? ReconciliationService::lookup($dupRef) : null;
        $master = $masterRef ? ReconciliationService::lookup($masterRef) : null;
        if (!$dup || !$master || mb_strlen($reason) < 5) {
            Flash::set('danger', 'Both registry references and a justification (≥ 5 chars) are required.');
        } elseif (ReconciliationService::merge((int)$dup['id'], (int)$master['id'], (int)\App\Core\Auth::id(), $reason)) {
            Flash::set('success', "{$dup['master_ref']} merged into {$master['master_ref']}.");
        } else {
            Flash::set('danger', (string)ReconciliationService::$error);
        }
        header('Location: ' . Rbac::baseUrl() . '/reconciliation');
    }
}
