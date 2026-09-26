<?php
declare(strict_types=1);
/**
 * End-to-end acceptance suite (black-box HTTP + DB assertions).
 *
 * Runs against a FRESH database loaded from database/schema.sql + seed.sql —
 * it mutates data. Never point it at production.
 *
 *   FNCRB_DB_PORT=3307 php -S 127.0.0.1:8099 -t public public/dev_router.php &
 *   FNCRB_DB_PORT=3307 FNCRB_TEST_URL=http://127.0.0.1:8099 php tests/smoke.php
 */
if (PHP_SAPI !== 'cli') exit;
require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Database;

$BASE = rtrim(getenv('FNCRB_TEST_URL') ?: 'http://127.0.0.1:8099', '/');
const PW = 'ChangeMe!2026';

final class Client
{
    private string $jar;
    public string $csrf = '';
    public function __construct(private string $base) { $this->jar = tempnam(sys_get_temp_dir(), 'fncrb'); }

    /** @return array{0:int,1:string,2:array} */
    public function req(string $method, string $path, array|string|null $data = null, array $headers = [], bool $json = false, array $files = []): array
    {
        $ch = curl_init($this->base . $path);
        $opts = [
            CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true,
            CURLOPT_COOKIEJAR => $this->jar, CURLOPT_COOKIEFILE => $this->jar, CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT => 30,
        ];
        if ($json) {
            $headers[] = 'Content-Type: application/json';
            $headers[] = 'Accept: application/json';
            $headers[] = 'X-CSRF-Token: ' . $this->csrf;
            $opts[CURLOPT_POSTFIELDS] = is_string($data) ? $data : json_encode($data);
        } elseif ($data !== null || $files) {
            $fields = (array)$data;
            foreach ($files as $k => $f) $fields[$k] = new CURLFile($f[0], $f[1], basename($f[0]));
            $opts[CURLOPT_POSTFIELDS] = $files ? $fields : http_build_query($fields);
        }
        $opts[CURLOPT_HTTPHEADER] = $headers;
        curl_setopt_array($ch, $opts);
        $raw = (string)curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $hs = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);
        $body = substr($raw, $hs);
        $hdrs = [];
        foreach (explode("\r\n", substr($raw, 0, $hs)) as $line) {
            if (str_contains($line, ':')) { [$k, $v] = explode(':', $line, 2); $hdrs[strtolower(trim($k))] = trim($v); }
        }
        if (preg_match('/name="csrf-token" content="([a-f0-9]+)"/', $body, $m) || preg_match('/name="_csrf" value="([a-f0-9]+)"/', $body, $m)) {
            $this->csrf = $m[1];
        }
        return [$status, $body, $hdrs];
    }

    public function get(string $p): array { return $this->req('GET', $p); }

    public function post(string $p, array $d = [], array $files = []): array
    {
        return $this->req('POST', $p, $d + ['_csrf' => $this->csrf], [], false, $files);
    }

    public function json(string $p, array $d): array { return $this->req('POST', $p, $d, [], true); }

    public function login(string $email, string $pw = PW, array $extra = []): array
    {
        $this->get('/login');
        return $this->post('/login', ['email' => $email, 'password' => $pw, 'terms_accepted' => '1'] + $extra);
    }
}

$pass = 0; $fail = 0;
function check(string $name, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    if ($ok) { $pass++; echo "  PASS  $name\n"; }
    else { $fail++; echo "  FAIL  $name" . ($detail !== '' ? " — " . substr(preg_replace('/\s+/', ' ', strip_tags($detail)), 0, 300) : '') . "\n"; }
}
function db(): PDO { return Database::pdo(); }
function one(string $sql, array $p = []): mixed { $s = db()->prepare($sql); $s->execute($p); return $s->fetchColumn(); }

/** Signed machine-API call. */
function api(string $base, string $key, string $path, array|string $body): array
{
    $raw = is_string($body) ? $body : json_encode($body);
    $ts = (string)time();
    $nonce = bin2hex(random_bytes(16));
    $sig = hash_hmac('sha256', "$ts.$nonce.$raw", $key);
    $ch = curl_init($base . $path);
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $raw, CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => [
        'Content-Type: application/json', "X-FNCRB-Key: $key", "X-FNCRB-Timestamp: $ts", "X-FNCRB-Nonce: $nonce", "X-FNCRB-Signature: $sig",
    ]]);
    $out = (string)curl_exec($ch);
    $st = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    return [$st, json_decode($out, true) ?? $out];
}

echo "FNCRB smoke suite → $BASE\n";

// ---------------------------------------------------------------- U-1 access & auth
echo "\n[U-1] Access control & authentication\n";
$g = new Client($BASE);
check('landing 200', $g->get('/')[0] === 200);
[$st, $body] = $g->login('officer@microbank.cm', 'wrong-password');
check('bad credentials refused', str_contains($body, 'Invalid credentials'), $body);
$g->get('/login');
[$st, $body] = $g->post('/login', ['email' => 'officer@microbank.cm', 'password' => PW]);
check('terms gate enforced server-side', str_contains($body, 'Terms'), $body);
check('unauthenticated redirect', $g->get('/loans')[0] === 302);
[$st] = $g->req('GET', '/login', null, ['X-FNCRB-Key: not-a-real-key']);
check('bogus API key header on web route does not crash (was 500)', $st === 200, (string)$st);
[$st] = $g->req('POST', '/login', ['email' => 'x@y.z', 'password' => 'p', 'terms_accepted' => 1]);
check('CSRF missing → 419', $st === 419, (string)$st);

$off = new Client($BASE);
[$st, , $h] = $off->login('officer@microbank.cm');
check('officer login redirects to dashboard', $st === 302 && str_ends_with($h['location'] ?? '', '/dashboard'), json_encode($h));
check('dashboard renders', $off->get('/dashboard')[0] === 200);
[$st] = $off->get('/logout');
check('GET /logout does not sign out (CSRF-safe)', $off->get('/dashboard')[0] === 200);

// ---------------------------------------------------------------- U-2 scoping / leaks
echo "\n[U-2] Institution data scoping\n";
[, $body] = $off->get('/borrowers');
check('officer borrower list excludes unrelated borrower (Hamadou Sali)', !str_contains($body, 'Hamadou'), $body);
check('officer borrower list includes own borrower', str_contains($body, 'Etienne Tabi'));
[, $body] = $off->get('/borrowers?q=117812390');
check('exact identifier gives minimal registry match', str_contains($body, 'Registry match') && str_contains($body, 'FNB000000004'), $body);
[, $body] = $off->get('/loans');
check('officer loans are own institution only', !str_contains($body, 'CAM-2025-0041') && str_contains($body, 'MBK-2024-0777'));
[, $body] = $off->get('/inquiry');
check('inquiry form no longer dumps the registry', !str_contains($body, 'Hamadou') && str_contains($body, 'Borrower identifier'));

$comp = new Client($BASE);
$comp->login('compliance@microbank.cm');
[$st, $body] = $comp->get('/compliance/concentration');
$conc = json_decode($body, true);
check('concentration JSON scoped to own institution', $st === 200 && count($conc['institutions'] ?? []) === 1
    && str_contains(json_encode($conc), 'MICROBANK-PL') && !str_contains(json_encode($conc), 'AFRIBANK'), $body);
[$st, $body] = $comp->get('/compliance/supervisory-package');
check('supervisory package (web) scoped to institution', $st === 200 && (json_decode($body, true)['scope'] ?? '') === 'institution', $body);
[$st] = $comp->get('/api/v1/supervisory-package');
check('national API package refused to institution user', $st === 401, (string)$st);

$aud = new Client($BASE);
$aud->login('auditor@microbank.cm');
[$st, $body] = $aud->get('/audit');
check('institution auditor sees only own-institution audit rows', $st === 200 && !preg_match('#<td>(CAMCCUL|AFRIBANK-CM|PROJFIN-CM)</td>#', $body), $body);
check('auditor cannot run full chain verification', $aud->post('/audit/verify')[0] === 403);

$adm = new Client($BASE);
$adm->login('admin@microbank.cm');
[, $body] = $adm->get('/analytics');
check('institution analytics hides national platform health', !str_contains($body, 'API requests (24h)') && !str_contains($body, 'Macro-Financial'), $body);

$sup = new Client($BASE);
$sup->login('supervisor@cobac.cm');
[$st, $body] = $sup->get('/api/v1/supervisory-package');
check('regulator gets national supervisory package', $st === 200 && (json_decode($body, true)['scope'] ?? '') === 'national', $body);
[, $body] = $sup->get('/loans');
check('regulator national loan view', str_contains($body, 'CAM-2025-0041') && str_contains($body, 'MBK-2024-0777'));

// ---------------------------------------------------------------- U-3 consent-gated inquiry
echo "\n[U-3] Consent-gated inquiry\n";
$today = date('Y-m-d');
[, $body] = $off->post('/inquiry', ['identifier' => '118545678', 'consent_type' => 'PHYSICAL', 'consent_ref' => 'CS-T-001',
    'consent_signed_at' => $today, 'purpose' => 'underwriting', 'consent_attested' => '1']);
check('physical consent without signed form refused', str_contains($body, 'scanned signed form'), $body);
[, $body] = $off->post('/inquiry', ['identifier' => '118545678', 'consent_type' => 'DIGITAL', 'consent_ref' => 'CS-T-002',
    'consent_signed_at' => $today, 'purpose' => 'underwriting']);
check('attestation required', str_contains($body, 'attest'), $body);
[, $body] = $off->post('/inquiry', ['identifier' => '118545678', 'consent_type' => 'DIGITAL', 'consent_ref' => 'CS-T-002',
    'consent_signed_at' => date('Y-m-d', strtotime('-90 days')), 'purpose' => 'underwriting', 'consent_attested' => '1']);
check('stale consent signature refused', str_contains($body, 'more than'), $body);
$pdf = tempnam(sys_get_temp_dir(), 'ev') . '.pdf';
file_put_contents($pdf, "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n");
[, $body] = $off->post('/inquiry', ['identifier' => '118545678', 'consent_type' => 'PHYSICAL', 'consent_ref' => 'CS-T-003',
    'consent_signed_at' => $today, 'purpose' => 'underwriting', 'consent_attested' => '1', 'declared_monthly_income' => '900000'],
    ['consent_evidence' => [$pdf, 'application/pdf']]);
check('inquiry with evidenced consent renders report', str_contains($body, 'Credit report') && str_contains($body, 'MBK-2024-0777'), $body);
check('report shows adverse reason codes', str_contains($body, 'Main factors lowering the score'));
$cid = (int)one("SELECT id FROM consents WHERE consent_ref = 'CS-T-003'");
check('consent stored with evidence hash + capturing user', $cid > 0 && one("SELECT evidence_sha256 FROM consents WHERE id = ?", [$cid]) === hash_file('sha256', $pdf)
    && one("SELECT captured_by FROM consents WHERE id = ?", [$cid]) !== null);
[$st, $body, $h] = $off->get("/consents/evidence?id=$cid");
check('evidence retrievable by own institution', $st === 200 && str_starts_with($body, '%PDF'), (string)$st);
$other = new Client($BASE);
$other->login('officer@camccul.cm');
check('evidence not retrievable by another institution', $other->get("/consents/evidence?id=$cid")[0] === 404);
[, $body] = $off->post('/inquiry', ['identifier' => '119088234', 'consent_type' => 'DIGITAL', 'consent_ref' => 'CS-T-003',
    'consent_signed_at' => $today, 'purpose' => 'underwriting', 'consent_attested' => '1']);
check('consent reference cannot be reused for another borrower', str_contains($body, 'another borrower'), $body);
[, $body] = $off->post('/consents/revoke', ['id' => (string)$cid, 'reason' => 'borrower withdrew']);
check('consent revocation', one("SELECT revoked_at IS NOT NULL FROM consents WHERE id = ?", [$cid]) == 1);
check('thin-file borrower is Not Rated', \App\Services\ScoringService::score((int)one("SELECT id FROM borrowers WHERE master_ref='FNB000000002'"))['risk_grade'] !== 'NR'
    && (function () { db()->exec("INSERT INTO borrowers (master_ref, full_name, cni_number) VALUES ('FNB999999999','Thin File','TF-1')");
        return \App\Services\ScoringService::score((int)db()->lastInsertId())['risk_grade'] === 'NR'; })());

// ---------------------------------------------------------------- U-4 ingestion & reconciliation
echo "\n[U-4] Ingestion, identity reconciliation, history\n";
[, $body] = $off->post('/loans', ['borrower_identifier' => 'FNB000000002', 'contract_ref' => 'MBK-2026-S01', 'loan_type' => 'CONSUMER',
    'principal_xaf' => '500000', 'outstanding_xaf' => '0', 'start_date' => '2025-01-01', 'maturity_date' => '2026-01-01',
    'reported_at' => $today, 'status' => 'SETTLED']);
check('settled loan with outstanding 0 accepted (was rejected)', one("SELECT status FROM loans WHERE contract_ref='MBK-2026-S01'") === 'SETTLED', $body);
[, $body] = $off->post('/loans', ['borrower_identifier' => 'FNB000000001', 'contract_ref' => 'MBK-2024-0777', 'loan_type' => 'BUSINESS',
    'principal_xaf' => '8000000', 'outstanding_xaf' => '6500000', 'start_date' => '2024-09-15', 'maturity_date' => '2027-09-15',
    'reported_at' => $today, 'days_past_due' => '10', 'status' => 'RESTRUCTURED']);
check('update keeps previous period in loan_history', (int)one("SELECT COUNT(*) FROM loan_history h JOIN loans l ON l.id=h.loan_id WHERE l.contract_ref='MBK-2024-0777' AND h.reported_at='2026-08-31'") === 1, $body);
check('restructured credit floored at WATCH', one("SELECT cobac_class FROM loans WHERE contract_ref='MBK-2024-0777'") === 'WATCH');

$key = 'fncrb_test_' . bin2hex(random_bytes(8));
db()->prepare("UPDATE institutions SET api_key_hash = ? WHERE code = 'MICROBANK-PL'")->execute([hash('sha256', $key)]);
[$st, $r] = api($BASE, $key, '/api/v1/loans', ['schema_version' => '1.0', 'loans' => [
    ['borrower' => ['full_name' => 'John Doe', 'cni_number' => '118545678'], 'contract_ref' => 'MBK-API-1', 'loan_type' => 'MICRO',
     'principal_xaf' => 100000, 'outstanding_xaf' => 50000, 'start_date' => '2026-01-01', 'maturity_date' => '2027-01-01', 'reported_at' => $today],
    ['full_name' => 'No Borrower Block', 'contract_ref' => 'MBK-API-2', 'loan_type' => 'MICRO', 'principal_xaf' => 100000,
     'outstanding_xaf' => 50000, 'start_date' => '2026-01-01', 'maturity_date' => '2027-01-01', 'reported_at' => $today],
    ['borrower' => ['full_name' => 'Tabi Etienne', 'cni_number' => '118545678'], 'contract_ref' => 'MBK-API-3', 'loan_type' => 'MICRO',
     'principal_xaf' => 'abc', 'outstanding_xaf' => 50000, 'start_date' => '2026-13-01', 'maturity_date' => '2027-01-01', 'reported_at' => $today],
    ['borrower' => ['full_name' => 'TABI Étienne', 'cni_number' => '118545678'], 'contract_ref' => 'MBK-API-4', 'loan_type' => 'MICRO',
     'principal_xaf' => 100000, 'outstanding_xaf' => 50000, 'start_date' => '2026-01-01', 'maturity_date' => '2027-01-01', 'reported_at' => $today,
     'guarantors' => [['full_name' => 'Clarisse Abena', 'cni_number' => '119088234', 'guarantee_xaf' => 25000]]],
]]);
$msgs = json_encode($r);
check('API batch: bad records isolated, good ones accepted (no 500)', $st === 200 && ($r['accepted'] ?? 0) === 1 && ($r['rejected'] ?? 0) === 3, $msgs);
check('name mismatch on known CNI parked as IDENTITY_CONFLICT', str_contains($msgs, 'IDENTITY_CONFLICT') && (int)one("SELECT COUNT(*) FROM identity_conflicts WHERE status='OPEN'") === 1, $msgs);
check('flat record without identifier rejected with message', str_contains($msgs, 'identifier'), $msgs);
check('type/date validation messages', str_contains($msgs, 'principal_xaf') && str_contains($msgs, 'start_date'), $msgs);
check('accent/order name variant reconciled to existing borrower', one("SELECT b.master_ref FROM loans l JOIN borrowers b ON b.id=l.borrower_id WHERE l.contract_ref='MBK-API-4'") === 'FNB000000001');
check('guarantor captured and linked to registry borrower', (int)one("SELECT g.borrower_id FROM guarantors g JOIN loans l ON l.id=g.loan_id WHERE l.contract_ref='MBK-API-4'") === (int)one("SELECT id FROM borrowers WHERE cni_number='119088234'"));
[$st, $r] = api($BASE, $key, '/api/v1/loans', ['schema_version' => '1.0', 'contract_ref' => 'X']);
check('API body without "loans" array → 422 (was 500)', $st === 422, json_encode($r));
[$st, $r] = api($BASE, $key, '/api/v1/inquiry', ['schema_version' => '1.0', 'cni_number' => '118545678', 'consent_ref' => 'API-C-1', 'consent_type' => 'DIGITAL']);
check('API consent without signature date refused', $st === 422 && ($r['error']['code'] ?? '') === 'CONSENT_INCOMPLETE', json_encode($r));
[$st, $r] = api($BASE, $key, '/api/v1/inquiry', ['schema_version' => '1.0', 'cni_number' => '118545678', 'consent_ref' => 'API-C-1', 'consent_type' => 'DIGITAL', 'consent_signed_at' => $today]);
check('API inquiry with complete consent', $st === 200 && isset($r['score']['reasons']), json_encode($r));
[$st, $r] = api($BASE, $key, '/api/v1/incidents', ['schema_version' => '1.0', 'incident' => ['borrower_identifier' => '118545678',
    'incident_type' => 'BOUNCED_CHEQUE', 'instrument_ref' => 'CHQ-API-1', 'amount_xaf' => 10000, 'incident_date' => $today]]);
check('API CIP incident feed', $st === 201, json_encode($r));

$super = new Client($BASE);
$super->login('admin@fncrb.cm');
[, $body] = $super->get('/reconciliation');
check('bureau reconciliation queue lists the conflict', str_contains($body, 'NAME_MISMATCH'), $body);
$conf = (int)one("SELECT id FROM identity_conflicts WHERE status='OPEN'");
$super->post('/reconciliation/resolve', ['id' => (string)$conf, 'decision' => 'REJECTED', 'note' => 'different person, same CNI typo']);
check('conflict decision recorded', one("SELECT status FROM identity_conflicts WHERE id=?", [$conf]) === 'REJECTED');

// ---------------------------------------------------------------- U-5 collateral / incidents
echo "\n[U-5] Collateral & incidents\n";
[$st, $body] = $off->post('/collateral', ['contract_ref' => 'CAM-2025-0041', 'collateral_type' => 'PLEDGE', 'description' => 'someone else loan']);
check('cannot pledge collateral on another institution\'s loan', (int)one("SELECT COUNT(*) FROM collateral WHERE description='someone else loan'") === 0);
[$st, $r] = $off->json('/collateral', ['contract_ref' => 'MBK-2024-0777', 'collateral_type' => 'PLEDGE', 'description' => 'dup', 'rccm_registration_no' => 'RCCM-DLA-SU-2025-0140']);
check('RCCM double pledge blocked (409) and audited with RCCM ref', $st === 409 && str_contains((string)one("SELECT details FROM audit_logs WHERE action='DOUBLE_PLEDGE_BLOCKED' ORDER BY id DESC LIMIT 1"), 'RCCM-DLA-SU-2025-0140'), $r);
[$st, $r] = $off->json('/collateral', ['contract_ref' => 'MBK-2024-0777', 'collateral_type' => 'VEHICLE_MORTGAGE', 'description' => 'Hilux', 'rccm_registration_no' => 'RCCM-T-1']);
check('collateral registration', $st === 200, $r);
$cid2 = (int)one("SELECT id FROM collateral WHERE rccm_registration_no='RCCM-T-1'");
$off->post('/collateral/status', ['id' => (string)$cid2, 'status' => 'RELEASED']);
check('collateral release', one("SELECT status FROM collateral WHERE id=?", [$cid2]) === 'RELEASED');
$off->post('/incidents', ['borrower_identifier' => '118545678', 'incident_type' => 'BOUNCED_CHEQUE', 'instrument_ref' => 'CHQ-T-9', 'amount_xaf' => '5000', 'incident_date' => $today]);
$iid = (int)one("SELECT id FROM payment_incidents WHERE instrument_ref='CHQ-T-9'");
check('incident reported via web form', $iid > 0);
$off->post('/incidents/resolve', ['id' => (string)$iid, 'resolved_at' => $today, 'note' => 'cheque honoured']);
check('incident regularized', (int)one("SELECT resolved FROM payment_incidents WHERE id=?", [$iid]) === 1);

// ---------------------------------------------------------------- U-6 disputes
echo "\n[U-6] Disputes\n";
$off->post('/disputes', ['borrower_identifier' => '118545678', 'dispute_type' => 'INACCURATE_BALANCE', 'contract_ref' => 'CAM-2025-0041', 'against_inst_id' => (string)one("SELECT id FROM institutions WHERE code='CAMCCUL'"), 'details' => 'Balance already repaid in full.']);
$did = (int)one("SELECT id FROM disputes ORDER BY id DESC LIMIT 1");
check('dispute filed against the furnisher of the loan', $did > 0 && (int)one("SELECT against_inst_id FROM disputes WHERE id=?", [$did]) === (int)one("SELECT id FROM institutions WHERE code='CAMCCUL'"));
$cam = new Client($BASE);
$cam->login('officer@camccul.cm');
[, $body] = $cam->get('/disputes');
check('furnisher institution sees dispute about its data', str_contains($body, 'INACCURATE BALANCE'), $body);
$cam->post('/disputes/respond', ['id' => (string)$did, 'response' => 'Confirmed: repaid on 2026-08-30.']);
check('furnisher response recorded', one("SELECT furnisher_response FROM disputes WHERE id=?", [$did]) !== null);
$loanId = (int)one("SELECT id FROM loans WHERE contract_ref='CAM-2025-0041'");
[$st] = $sup->json('/disputes/transition', ['id' => $did, 'status' => 'CORRECTED', 'note' => 'x', 'correction' => ['entity' => 'loans', 'entity_id' => $loanId, 'field' => 'outstanding_xaf', 'new_value' => '0']]);
check('illegal OPEN→CORRECTED refused and NO data changed (was applied anyway)', $st === 422 && (int)one("SELECT outstanding_xaf FROM loans WHERE id=?", [$loanId]) === 900000);
$sup->json('/disputes/transition', ['id' => $did, 'status' => 'UNDER_REVIEW', 'note' => 'review']);
[$st] = $sup->json('/disputes/transition', ['id' => $did, 'status' => 'CORRECTED', 'note' => 'furnisher confirmed', 'correction' => ['entity' => 'loans', 'entity_id' => (int)one("SELECT id FROM loans WHERE contract_ref='PFC-2025-0113'"), 'field' => 'days_past_due', 'new_value' => '0']]);
check('correction outside the disputed credit refused', $st === 422);
[$st] = $sup->json('/disputes/transition', ['id' => $did, 'status' => 'CORRECTED', 'note' => 'furnisher confirmed', 'correction' => ['entity' => 'loans', 'entity_id' => $loanId, 'field' => 'days_past_due', 'new_value' => '120']]);
check('correction applied + loan reclassified', $st === 200 && one("SELECT cobac_class FROM loans WHERE id=?", [$loanId]) === 'UNCERTAIN');
$camKey = 'fncrb_cam_' . bin2hex(random_bytes(8));
db()->prepare("UPDATE institutions SET api_key_hash = ? WHERE code = 'CAMCCUL'")->execute([hash('sha256', $camKey)]);
[$st, $r] = api($BASE, $camKey, '/api/v1/loans', ['schema_version' => '1.0', 'loans' => [
    ['borrower' => ['master_ref' => 'FNB000000001'], 'contract_ref' => 'CAM-2025-0041', 'loan_type' => 'MICRO', 'principal_xaf' => 1500000,
     'outstanding_xaf' => 900000, 'start_date' => '2025-02-10', 'maturity_date' => '2026-02-10', 'reported_at' => $today, 'days_past_due' => 0]]]);
check('re-reporting the disputed value is blocked (CORRECTION_REVERT)', str_contains(json_encode($r), 'CORRECTION_REVERT'), json_encode($r));
for ($i = 0; $i < 3; $i++) $off->post('/disputes', ['borrower_identifier' => 'FNB000000001', 'dispute_type' => 'OTHER', 'details' => "bulk dispute number $i"]);
check('dispute references unique', (int)one("SELECT COUNT(DISTINCT reference) FROM disputes") === (int)one("SELECT COUNT(*) FROM disputes"));

// ---------------------------------------------------------------- U-7 user lifecycle & sessions
echo "\n[U-7] User lifecycle & session revocation\n";
$adm->get('/users/create');
[, $body] = $adm->post('/users', ['full_name' => 'Test Officer', 'email' => 'test.officer@microbank.cm', 'role_code' => 'CREDIT_OFFICER', 'officer_level' => '1']);
preg_match('#Password: <code>([^<]+)</code>#', $body, $m);
$tmpPw = html_entity_decode($m[1] ?? '');
check('user created with one-time password', $tmpPw !== '', $body);
$new = new Client($BASE);
[$st, , $h] = $new->login('test.officer@microbank.cm', $tmpPw);
check('first login forced to change password', str_contains($h['location'] ?? '', '/account?must_change=1'), json_encode($h));
[$st, , $h] = $new->get('/loans');
check('other pages blocked until password changed', $st === 302 && str_contains($h['location'] ?? '', 'must_change'), (string)$st);
$new->get('/account');
$new->post('/account/password', ['current_password' => $tmpPw, 'new_password' => 'Brand-New-Pass1', 'confirm_password' => 'Brand-New-Pass1']);
check('after change the user can work', $new->get('/loans')[0] === 200);
$uid = (int)one("SELECT id FROM users WHERE email='test.officer@microbank.cm'");
$adm->get('/users');
[$st, $r] = $adm->json('/users/toggle', ['id' => $uid]);
check('admin locks user', $st === 200 && str_contains($r, 'LOCKED'), $r);
[$st, , $h] = $new->get('/loans');
check('locked user\'s live session ends immediately', $st === 302 && str_ends_with($h['location'] ?? '', '/login'), (string)$st);
$adm->json('/users/toggle', ['id' => $uid]);
for ($i = 0; $i < 5; $i++) (new Client($BASE))->login('test.officer@microbank.cm', 'wrong-' . $i);
[, $body] = (new Client($BASE))->login('test.officer@microbank.cm', 'Brand-New-Pass1');
check('account lockout after repeated failures (independent of IP)', str_contains($body, 'Too many failed attempts'), $body);

// 2FA: enrolment, challenge, replay protection, step-up to disable, legacy seed migration
$mfa = new Client($BASE);
$mfa->login('compliance@microbank.cm');
$mfa->get('/account');
[, $body] = $mfa->post('/account/2fa/start');
preg_match('#<code style="font-size:13px;word-break:break-all;">([A-Z2-7]+)</code>#', $body, $m);
$seed = $m[1] ?? '';
$mfa->post('/account/2fa/confirm', ['otp' => \App\Core\Totp::code($seed)]);
$stored = (string)one("SELECT totp_secret FROM users WHERE email='compliance@microbank.cm'");
check('2FA enrolled with encrypted seed', $seed !== '' && str_starts_with($stored, 'enc:v1:') && !str_contains($stored, $seed), $stored);
$c2 = new Client($BASE);
[, $body] = $c2->login('compliance@microbank.cm');
check('login challenges for one-time code', str_contains($body, 'One-time code'), $body);
sleep(1);
$nextCode = \App\Core\Totp::code($seed, time() + 30); // enrolment consumed the current step
[$st, , $h] = $c2->login('compliance@microbank.cm', PW, ['otp' => $nextCode]);
check('login with valid code succeeds', $st === 302 && str_ends_with($h['location'] ?? '', '/dashboard'), json_encode($h));
[, $body] = (new Client($BASE))->login('compliance@microbank.cm', PW, ['otp' => $nextCode]);
check('same code cannot be replayed', str_contains($body, 'Invalid or missing one-time code'), $body);
$c2->get('/account');
[, $body] = $c2->post('/account/2fa/disable', ['current_password' => 'wrong', 'otp' => '000000']);
check('disabling 2FA requires password + code', str_contains($body, '2FA remains active') && one("SELECT totp_secret IS NOT NULL FROM users WHERE email='compliance@microbank.cm'") == 1, $body);
db()->prepare("UPDATE users SET totp_secret = ?, totp_last_step = NULL WHERE email = 'auditor@microbank.cm'")->execute(['JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP']);
[$st] = (new Client($BASE))->login('auditor@microbank.cm', PW, ['otp' => \App\Core\Totp::code('JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP')]);
check('legacy plaintext seed still works and is encrypted on use', $st === 302 && str_starts_with((string)one("SELECT totp_secret FROM users WHERE email='auditor@microbank.cm'"), 'enc:v1:'));

// institution suspension
$sus = new Client($BASE);
$sus->login('officer@camccul.cm');
$super->get('/institutions');
$camId = (int)one("SELECT id FROM institutions WHERE code='CAMCCUL'");
$super->get("/institutions/edit?id=$camId");
$super->post('/institutions/update', ['id' => (string)$camId, 'name' => 'CAMCCUL Network', 'net_equity_xaf' => '4800000000', 'status' => 'SUSPENDED']);
[$st, , $h] = $sus->get('/dashboard');
check('suspending an institution ends its users\' sessions', $st === 302 && str_ends_with($h['location'] ?? '', '/login'), (string)$st);
[$st, $r] = api($BASE, $camKey, '/api/v1/loans', ['schema_version' => '1.0', 'loans' => [['x' => 1]]]);
check('suspended institution API key refused', $st === 401, json_encode($r));
$super->post('/institutions/update', ['id' => (string)$camId, 'name' => 'CAMCCUL Network', 'net_equity_xaf' => '4800000000', 'status' => 'ACTIVE']);
$super->post('/institutions/rotate-key', ['id' => (string)$camId]);
[$st] = api($BASE, $camKey, '/api/v1/loans', ['schema_version' => '1.0', 'loans' => [['x' => 1]]]);
check('rotated API key invalidates the old one', $st === 401);

// super admin creates national + institution users
$super->get('/users/create');
[, $body] = $super->post('/users', ['full_name' => 'New Supervisor', 'email' => 'sup2@cobac.cm', 'role_code' => 'REGULATOR']);
check('super admin can create a national (regulator) user', str_contains($body, 'User created'), $body);
[, $body] = $super->post('/users', ['full_name' => 'Proj Admin', 'email' => 'admin@projfin.cm', 'role_code' => 'INST_ADMIN', 'institution_id' => (string)one("SELECT id FROM institutions WHERE code='PROJFIN-CM'")]);
check('super admin can create an institution admin', str_contains($body, 'User created'), $body);

// ---------------------------------------------------------------- U-8 audit trail
echo "\n[U-8] Audit trail integrity\n";
$r = \App\Core\Audit::verify(true);
check('full chain verifies after the whole run', $r['ok'] === true && $r['checked'] > 50, json_encode($r));
$victim = (int)one("SELECT id FROM audit_logs WHERE hash_version = 2 ORDER BY id DESC LIMIT 1 OFFSET 5");
db()->prepare("UPDATE audit_logs SET details = JSON_OBJECT('email','forged') WHERE id = ?")->execute([$victim]);
$r = \App\Core\Audit::verify(true);
check('editing a row\'s content is detected (was undetected)', $r['ok'] === false && $r['broken_at'] === $victim && str_contains((string)$r['reason'], 'altered'), json_encode($r));
[, $body] = $super->get('/audit');
check('audit page shows the break', str_contains($body, 'CHAIN BROKEN'), $body);

// concurrency: parallel writers must not fork the chain
$mh = curl_multi_init(); $hs = [];
for ($i = 0; $i < 12; $i++) {
    $ch = curl_init($BASE . '/login'); // each failed-terms login writes an audit entry
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_POSTFIELDS => '']);
    curl_multi_add_handle($mh, $ch); $hs[] = $ch;
}
do { curl_multi_exec($mh, $running); curl_multi_select($mh); } while ($running);
$forks = (int)one("SELECT COUNT(*) FROM (SELECT prev_hash FROM audit_logs WHERE hash_version=2 GROUP BY prev_hash HAVING COUNT(*) > 1) t");
check('concurrent audit writes do not fork the chain', $forks === 0, "$forks forks");

// ---------------------------------------------------------------- U-9 exports
echo "\n[U-9] Exports\n";
db()->prepare("UPDATE borrowers SET full_name = '=HYPERLINK(\"http://x\")' WHERE master_ref = 'FNB000000002'")->execute();
[$st, $body, $h] = $adm->get('/reports/loans.csv');
check('CSV has integrity header', isset($h['x-report-sha256']) && hash('sha256', $body) === $h['x-report-sha256']);
check('CSV formula injection neutralized', str_contains($body, "'=HYPERLINK") && !preg_match('/(^|,)"?=HYPERLINK/m', $body), substr($body, 0, 400));
[$st, $body] = $adm->get('/reports/loans.xlsx');
$tmp = tempnam(sys_get_temp_dir(), 'x');
file_put_contents($tmp, $body);
$zip = new ZipArchive();
check('XLSX contains a styles part (Excel repair prompt fixed)', $zip->open($tmp) === true && $zip->locateName('xl/styles.xml') !== false);

// ---------------------------------------------------------------- U-10 pages render
echo "\n[U-10] Pages render for each role\n";
foreach ([[$super, ['/dashboard', '/institutions', '/reconciliation', '/users', '/analytics', '/audit', '/inquiries', '/consents', '/borrowers', '/borrowers/report?ref=FNB000000001', '/disputes', '/collateral', '/incidents', '/compliance', '/loans']],
          [$off, ['/dashboard', '/inquiry', '/inquiries', '/consents', '/borrowers', '/loans', '/loans/create', '/collateral', '/incidents', '/disputes', '/disputes/create', '/account']],
          [$sup, ['/dashboard', '/analytics', '/inquiries', '/consents', '/audit', '/disputes', '/borrowers/report?ref=118545678', '/loans?dpd=180%2B', '/loans?dpd=180+']]] as [$c, $pages]) {
    foreach ($pages as $p) {
        [$st, $body] = $c->get($p);
        check("GET $p → 200", $st === 200 && !str_contains($body, 'Fatal error') && !str_contains($body, 'Warning:'), $st . ' ' . $body);
    }
}
check('officer denied bureau pages', $off->get('/institutions')[0] === 403 && $off->get('/reconciliation')[0] === 403 && $off->get('/borrowers/report?ref=x')[0] === 403);

echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
