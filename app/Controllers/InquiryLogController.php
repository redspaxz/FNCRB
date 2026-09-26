<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Database;
use App\Core\Http;
use App\Core\Pagination;
use App\Core\Rbac;
use App\Core\Validator;
use App\Core\View;
use App\Services\InquiryService;

/**
 * Inquiry log (who consulted which credit file, under which consent).
 * inquiry.view.own → own institution; inquiry.view.all → national.
 */
final class InquiryLogController
{
    public function index(): void
    {
        Rbac::requireAny(['inquiry.view.own', 'inquiry.view.all']);
        $national = Auth::isNational() && Rbac::can('inquiry.view.all');
        $where = [];
        $params = [];
        if (!$national) { $where[] = 'q.institution_id = ?'; $params[] = Auth::institutionId(); }

        $channel = Validator::enum($_GET, 'channel', ['WEB', 'API']);
        if ($channel) { $where[] = 'q.channel = ?'; $params[] = $channel; }
        $ref = Validator::identifier($_GET, 'borrower', 40);
        if ($ref) { $where[] = 'b.master_ref = ?'; $params[] = $ref; }
        $from = Validator::date($_GET, 'from');
        if ($from) { $where[] = 'q.created_at >= ?'; $params[] = $from; }
        $to = Validator::date($_GET, 'to');
        if ($to) { $where[] = 'q.created_at < DATE_ADD(?, INTERVAL 1 DAY)'; $params[] = $to; }

        $sql = "FROM inquiry_logs q
                JOIN institutions i ON i.id = q.institution_id
                JOIN borrowers b ON b.id = q.borrower_id
                LEFT JOIN users u ON u.id = q.user_id
                LEFT JOIN consents c ON c.id = q.consent_id"
             . ($where ? ' WHERE ' . implode(' AND ', $where) : '');
        $page = Pagination::page();
        $perPage = Pagination::perPage();
        $count = Database::pdo()->prepare("SELECT COUNT(*) $sql");
        $count->execute($params);
        $total = (int)$count->fetchColumn();
        $stmt = Database::pdo()->prepare(
            "SELECT q.*, i.code AS inst_code, b.full_name, b.master_ref, u.full_name AS user_name,
                    c.consent_ref, c.consent_type, c.revoked_at $sql
             ORDER BY q.id DESC LIMIT $perPage OFFSET " . Pagination::offset($page, $perPage)
        );
        $stmt->execute($params);
        View::render('inquiries/index', [
            'rows' => $stmt->fetchAll(), 'national' => $national,
            'filters' => ['channel' => $channel, 'borrower' => $ref, 'from' => $from, 'to' => $to],
            'pager' => Pagination::render('/inquiries', $page, $perPage, $total),
        ]);
    }
}

/** Institution consent register: review, revoke, retrieve evidence. */
final class ConsentController
{
    public function index(): void
    {
        Rbac::requireAny(['inquiry.perform', 'inquiry.view.own', 'inquiry.view.all']);
        $national = Auth::isNational();
        $where = [];
        $params = [];
        if (!$national) { $where[] = 'c.institution_id = ?'; $params[] = Auth::institutionId(); }
        $state = Validator::enum($_GET, 'state', ['active', 'revoked', 'expired']);
        if ($state === 'active') $where[] = 'c.revoked_at IS NULL AND c.expires_at > NOW()';
        if ($state === 'revoked') $where[] = 'c.revoked_at IS NOT NULL';
        if ($state === 'expired') $where[] = 'c.revoked_at IS NULL AND c.expires_at <= NOW()';

        $sql = "FROM consents c JOIN borrowers b ON b.id = c.borrower_id JOIN institutions i ON i.id = c.institution_id
                LEFT JOIN users u ON u.id = c.captured_by" . ($where ? ' WHERE ' . implode(' AND ', $where) : '');
        $page = Pagination::page();
        $perPage = Pagination::perPage();
        $count = Database::pdo()->prepare("SELECT COUNT(*) $sql");
        $count->execute($params);
        $total = (int)$count->fetchColumn();
        $stmt = Database::pdo()->prepare(
            "SELECT c.*, b.full_name, b.master_ref, i.code AS inst_code, u.full_name AS captured_by_name $sql
             ORDER BY c.id DESC LIMIT $perPage OFFSET " . Pagination::offset($page, $perPage)
        );
        $stmt->execute($params);
        View::render('consents/index', [
            'rows' => $stmt->fetchAll(), 'national' => $national, 'state' => $state,
            'canRevoke' => !$national && Rbac::can('inquiry.perform'),
            'pager' => Pagination::render('/consents', $page, $perPage, $total),
        ]);
    }

    public function revoke(): void
    {
        Rbac::require('inquiry.perform');
        Http::verifyCsrf();
        $in = Http::input();
        $id = Validator::int($in, 'id', 1);
        $reason = Validator::string($in, 'reason', 255) ?? '';
        if (!$id || mb_strlen($reason) < 3) Http::done(false, 'Consent id and a revocation reason are required.', '/consents');
        $instId = Auth::institutionId();
        if ($instId === null) Http::done(false, 'Only the institution holding the consent can revoke it.', '/consents', [], 403);
        $ok = InquiryService::revokeConsent($id, $instId, (int)Auth::id(), $reason);
        Http::done($ok, $ok ? 'Consent revoked — no further inquiries may rely on it.' : (string)InquiryService::$error, '/consents');
    }

    /** Stream the stored consent evidence (own institution, or national supervisors). */
    public function evidence(): void
    {
        Rbac::requireAny(['inquiry.perform', 'inquiry.view.own', 'inquiry.view.all']);
        $id = Validator::int($_GET, 'id', 1);
        $stmt = Database::pdo()->prepare("SELECT * FROM consents WHERE id = ?");
        $stmt->execute([$id ?? 0]);
        $c = $stmt->fetch();
        if (!$c || !$c['evidence_path'] || (!Auth::isNational() && (int)$c['institution_id'] !== (int)Auth::institutionId())) {
            http_response_code(404);
            (new PageController())->notFound();
            return;
        }
        $path = dirname(__DIR__, 2) . '/storage/' . $c['evidence_path'];
        $real = realpath($path);
        if ($real === false || !str_starts_with($real, realpath(dirname(__DIR__, 2) . '/storage/consents')) || !is_file($real)) {
            http_response_code(404);
            (new PageController())->notFound();
            return;
        }
        if (!hash_equals((string)$c['evidence_sha256'], hash_file('sha256', $real))) {
            Audit::log('CONSENT_EVIDENCE_TAMPERED', ['type' => 'consent', 'id' => $c['id']]);
            (new PageController())->error(409, 'Evidence file fails its integrity check — escalate to the bureau.');
            return;
        }
        Audit::log('CONSENT_EVIDENCE_VIEWED', ['type' => 'consent', 'id' => $c['id']]);
        $ext = pathinfo($real, PATHINFO_EXTENSION);
        header('Content-Type: ' . (['pdf' => 'application/pdf', 'jpg' => 'image/jpeg', 'png' => 'image/png'][$ext] ?? 'application/octet-stream'));
        header('Content-Disposition: inline; filename="consent-' . (int)$c['id'] . '.' . $ext . '"');
        header('Cache-Control: no-store');
        readfile($real);
    }
}
