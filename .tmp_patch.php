<?php
$repo = 'C:/Users/Edmund Alomepe/.zcode/workspace/default/FNCRB/';

// ---------- LoanController::index (loans) — add COUNT + LIMIT/OFFSET ----------
$f = $repo . 'app/Controllers/LoanController.php';
$c = file_get_contents($f);

$c = str_replace(
"        if (\$where) \$sql .= \" WHERE \" . implode(' AND ', \$where);
        \$sql .= \" ORDER BY l.updated_at DESC LIMIT 200\";
        \$stmt = Database::pdo()->prepare(\$sql);
        \$stmt->execute(\$params);
        View::render('loans/index', ['loans' => \$stmt->fetchAll(), 'isRegulator' => \$isRegulator, 'filters' => compact('class', 'inst', 'period', 'dpd')]);",
"        if (\$where) \$sql .= \" WHERE \" . implode(' AND ', \$where);

        \$page = \\App\\Core\\Pagination::page();
        \$perPage = \\App\\Core\\Pagination::perPage();
        \$count = Database::pdo()->prepare(\"SELECT COUNT(*) FROM (\$sql) t\");
        \$count->execute(\$params);
        \$total = (int)\$count->fetchColumn();

        \$sql .= \" ORDER BY l.updated_at DESC LIMIT \$perPage OFFSET \" . \\App\\Core\\Pagination::offset(\$page, \$perPage);
        \$stmt = Database::pdo()->prepare(\$sql);
        \$stmt->execute(\$params);
        View::render('loans/index', [
            'loans' => \$stmt->fetchAll(), 'isRegulator' => \$isRegulator, 'filters' => compact('class', 'inst', 'period', 'dpd'),
            'pager' => \\App\\Core\\Pagination::render('/loans', \$page, \$perPage, \$total),
        ]);",
$c, $n1);

// ---------- IncidentController::index ----------
$c = str_replace(
"        if (\$where) \$sql .= \" WHERE \" . implode(' AND ', \$where);
        \$sql .= \" ORDER BY pi.incident_date DESC LIMIT 200\";
        \$stmt = Database::pdo()->prepare(\$sql);
        \$stmt->execute(\$params);
        View::render('incidents/index', ['incidents' => \$stmt->fetchAll(), 'isRegulator' => \$isRegulator, 'typeFilter' => \$type ?: null]);",
"        if (\$where) \$sql .= \" WHERE \" . implode(' AND ', \$where);

        \$page = \\App\\Core\\Pagination::page();
        \$perPage = \\App\\Core\\Pagination::perPage();
        \$count = Database::pdo()->prepare(\"SELECT COUNT(*) FROM (\$sql) t\");
        \$count->execute(\$params);
        \$total = (int)\$count->fetchColumn();

        \$sql .= \" ORDER BY pi.incident_date DESC LIMIT \$perPage OFFSET \" . \\App\\Core\\Pagination::offset(\$page, \$perPage);
        \$stmt = Database::pdo()->prepare(\$sql);
        \$stmt->execute(\$params);
        View::render('incidents/index', [
            'incidents' => \$stmt->fetchAll(), 'isRegulator' => \$isRegulator, 'typeFilter' => \$type ?: null,
            'pager' => \\App\\Core\\Pagination::render('/incidents', \$page, \$perPage, \$total),
        ]);",
$c, $n2);

// ---------- AuditController::index ----------
$c = str_replace(
"    public function index(): void
    {
        Rbac::require('audit.view');
        \$stmt = Database::pdo()->query(
            \"SELECT a.*, u.full_name, i.code AS inst_code
             FROM audit_logs a
             LEFT JOIN users u ON u.id = a.user_id
             LEFT JOIN institutions i ON i.id = a.institution_id
             ORDER BY a.id DESC LIMIT 300\"
        );
        [\$chainOk, \$brokenAt] = \\App\\Core\\Audit::verifyChain();
        View::render('audit/index', [
            'logs' => \$stmt->fetchAll(),
            'chainOk' => \$chainOk,
            'brokenAt' => \$brokenAt,
        ]);
    }",
"    public function index(): void
    {
        Rbac::require('audit.view');
        \$page = \\App\\Core\\Pagination::page();
        \$perPage = \\App\\Core\\Pagination::perPage(50);
        \$total = (int)Database::pdo()->query(\"SELECT COUNT(*) FROM audit_logs\")->fetchColumn();
        \$offset = \\App\\Core\\Pagination::offset(\$page, \$perPage);
        \$stmt = Database::pdo()->query(
            \"SELECT a.*, u.full_name, i.code AS inst_code
             FROM audit_logs a
             LEFT JOIN users u ON u.id = a.user_id
             LEFT JOIN institutions i ON i.id = a.institution_id
             ORDER BY a.id DESC LIMIT \$perPage OFFSET \$offset\"
        );
        [\$chainOk, \$brokenAt] = \\App\\Core\\Audit::verifyChain();
        View::render('audit/index', [
            'logs' => \$stmt->fetchAll(),
            'chainOk' => \$chainOk,
            'brokenAt' => \$brokenAt,
            'pager' => \\App\\Core\\Pagination::render('/audit', \$page, \$perPage, \$total),
        ]);
    }",
$c, $n3);
file_put_contents($f, $c);
echo "LoanController/Incidents/Audit: $n1/$n2/$n3\n";

// ---------- BorrowerController::index (search + paging) ----------
$f = $repo . 'app/Controllers/BorrowerController.php';
$c = file_get_contents($f);
$c = str_replace(
"        if (\$q !== '') {
            \$stmt = \$pdo->prepare(
                \"SELECT * FROM borrowers
                 WHERE dup_of_id IS NULL AND (full_name LIKE ? OR cni_number LIKE ? OR niu LIKE ? OR master_ref LIKE ? OR coop_member_id LIKE ?)
                 ORDER BY full_name LIMIT 50\"
            );
            \$like = \"%\$q%\";
            \$stmt->execute([\$like, \$like, \$like, \$like, \$like]);
        } else {
            \$stmt = \$pdo->query(\"SELECT * FROM borrowers WHERE dup_of_id IS NULL ORDER BY id DESC LIMIT 50\");
        }
        View::render('borrowers/index', ['borrowers' => \$stmt->fetchAll(), 'q' => \$q]);",
"        \$page = \\App\\Core\\Pagination::page();
        \$perPage = \\App\\Core\\Pagination::perPage();
        \$offset = \\App\\Core\\Pagination::offset(\$page, \$perPage);
        if (\$q !== '') {
            \$like = \"%\$q%\";
            \$cond = \"dup_of_id IS NULL AND (full_name LIKE ? OR cni_number LIKE ? OR niu LIKE ? OR master_ref LIKE ? OR coop_member_id LIKE ?)\";
            \$count = \$pdo->prepare(\"SELECT COUNT(*) FROM borrowers WHERE \$cond\");
            \$count->execute([\$like, \$like, \$like, \$like, \$like]);
            \$total = (int)\$count->fetchColumn();
            \$stmt = \$pdo->prepare(\"SELECT * FROM borrowers WHERE \$cond ORDER BY full_name LIMIT \$perPage OFFSET \$offset\");
            \$stmt->execute([\$like, \$like, \$like, \$like, \$like]);
        } else {
            \$total = (int)\$pdo->query(\"SELECT COUNT(*) FROM borrowers WHERE dup_of_id IS NULL\")->fetchColumn();
            \$stmt = \$pdo->query(\"SELECT * FROM borrowers WHERE dup_of_id IS NULL ORDER BY id DESC LIMIT \$perPage OFFSET \$offset\");
        }
        View::render('borrowers/index', [
            'borrowers' => \$stmt->fetchAll(), 'q' => \$q,
            'pager' => \\App\\Core\\Pagination::render('/borrowers', \$page, \$perPage, \$total),
        ]);",
$c, $n4);
file_put_contents($f, $c);
echo "Borrowers: $n4\n";

// ---------- DisputeService::list paging ----------
$f = $repo . 'app/Services/DisputeService.php';
$c = file_get_contents($f);
$c = str_replace(
"    public static function list(?int \$institutionId = null, ?string \$status = null): array
    {",
"    /** @return array{0: array rows, 1: int total} */
    public static function list(?int \$institutionId = null, ?string \$status = null, int \$limit = 25, int \$offset = 0): array
    {",
$c);
$c = str_replace(
"        \$sql .= \" ORDER BY FIELD(d.status,'OPEN','UNDER_REVIEW','CORRECTED','REJECTED','WITHDRAWN'), d.sla_due_at ASC\";
        \$stmt = Database::pdo()->prepare(\$sql);
        \$stmt->execute(\$params);
        return \$stmt->fetchAll();
    }",
"        \$count = Database::pdo()->prepare(\"SELECT COUNT(*) FROM (\$sql) t\");
        \$count->execute(\$params);
        \$total = (int)\$count->fetchColumn();

        \$sql .= \" ORDER BY FIELD(d.status,'OPEN','UNDER_REVIEW','CORRECTED','REJECTED','WITHDRAWN'), d.sla_due_at ASC LIMIT \$limit OFFSET \$offset\";
        \$stmt = Database::pdo()->prepare(\$sql);
        \$stmt->execute(\$params);
        return [\$stmt->fetchAll(), \$total];
    }",
$c);
file_put_contents($f, $c);
echo "DisputeService patched\n";

// ---------- DisputeController::index ----------
$f = $repo . 'app/Controllers/AnalyticsController.php';
$c = file_get_contents($f);
$c = str_replace(
"        \$status = trim((string)(\$_GET['status'] ?? '')) ?: null;
        View::render('disputes/index', [
            'disputes' => DisputeService::list(\$instId, \$status),
            'canWork' => \$canWork,
            'kpis' => DisputeService::kpis(),
            'statusFilter' => \$status,
        ]);",
"        \$status = trim((string)(\$_GET['status'] ?? '')) ?: null;
        \$page = \\App\\Core\\Pagination::page();
        \$perPage = \\App\\Core\\Pagination::perPage();
        [\$rows, \$total] = DisputeService::list(\$instId, \$status, \$perPage, \\App\\Core\\Pagination::offset(\$page, \$perPage));
        View::render('disputes/index', [
            'disputes' => \$rows,
            'canWork' => \$canWork,
            'kpis' => DisputeService::kpis(),
            'statusFilter' => \$status,
            'pager' => \\App\\Core\\Pagination::render('/disputes', \$page, \$perPage, \$total),
        ]);",
$c, $n5);
// ---------- UserController::index paging ----------
$c = str_replace(
"    public function index(): void
    {
        Rbac::require('users.manage');
        \$pdo = Database::pdo();
        if (\$this->isSuper() && isset(\$_GET['all'])) {",
"    public function index(): void
    {
        Rbac::require('users.manage');
        \$pdo = Database::pdo();
        \$page = \\App\\Core\\Pagination::page();
        \$perPage = \\App\\Core\\Pagination::perPage();
        \$offset = \\App\\Core\\Pagination::offset(\$page, \$perPage);
        if (\$this->isSuper() && isset(\$_GET['all'])) {",
$c);
$c = str_replace(
"                 ORDER BY u.institution_id IS NULL DESC, i.code, u.full_name\"
            );
        } else {",
"                 ORDER BY u.institution_id IS NULL DESC, i.code, u.full_name LIMIT \$perPage OFFSET \$offset\"
            );
        } else {",
$c);
$c = str_replace(
"                 WHERE u.institution_id = ? ORDER BY u.full_name\"
            );
            \$stmt->execute([\\App\\Core\\Auth::institutionId()]);
        }
        View::render('users/index', ['users' => \$stmt->fetchAll(), 'isSuper' => \$this->isSuper()]);",
"                 WHERE u.institution_id = ? ORDER BY u.full_name LIMIT \$perPage OFFSET \$offset\"
            );
            \$stmt->execute([\\App\\Core\\Auth::institutionId()]);
        }
        View::render('users/index', [
            'users' => \$stmt->fetchAll(), 'isSuper' => \$this->isSuper(),
            'pager' => \\App\\Core\\Pagination::render('/users', \$page, \$perPage, \$total ?? 0),
        ]);",
$c, $n6);
file_put_contents($f, $c);
echo "Disputes/UserController: $n5/$n6\n";
