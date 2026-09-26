# FNCRB — First National Credit Registry Bureau

Central Credit Registry (*Centrale des Risques / Bureau d'Information sur le Crédit*) for Cameroon / CEMAC.
Regulatory framework: **COBAC · BEAC · CNEF · OHADA Uniform Act**.

Stack: **PHP 8.1+ (modular monolith, MVC) · MySQL 8 · Bootstrap 5 · vanilla JavaScript**. No framework, no Composer dependency.

## Architecture

```
public/            front controller, .htaccess
app/
  Core/            Router, Database, Auth, Rbac (+ApiAuth), Csrf, Validator, Response, View, Audit
  Controllers/     Auth, Dashboard, Borrower (incl. inquiry), Loan, Collateral, Incident, Compliance, Audit, Api, Page
  Services/        Ingestion, Reconciliation, Exposure, Scoring, Classification, Concentration, Collateral, Inquiry, Report
  Views/           layouts + module views
  routes.php
config/config.php
database/          schema.sql, seed.sql
scripts/           make_api_key.php, ingest_batch.php (CLI batch ingestion)
```

### The five architecture modules (per specification)

1. **Data Ingestion & Centralization** — batch (CLI JSON) + API upsert of COBAC-standardized loans; identity reconciliation across CNI / NIU / cooperative IDs.
2. **Risk Verification & Credit Assessment** — cross-institution exposure engine, CIP payment-incident log, systemic 300–850 scoring (consistency, leverage, DTI, incidents, regional risk).
3. **OHADA Collateral & Security** — sûretés registration, RCCM cross-reference with **double-pledge blocking and detection**, guarantors & solidarity groups.
4. **COBAC Compliance & Reporting** — asset classification (Healthy/Watch/Uncertain/Doubtful/Compromised) with provisioning rates (0/5/20/40/100%), NPL ratios, concentration vs net equity, supervisory package (JSON) for BEAC/CNEF/COBAC.
5. **Institutional Access & Audit Security** — RBAC (6 roles × 17 permissions, institution-scoped queries, officer level), **consent-gated inquiries** (refused without recorded consent), **hash-chained tamper-proof audit trail** with chain verification.

### RBAC roles

SUPER_ADMIN · REGULATOR (COBAC/BEAC) · INST_ADMIN · COMPLIANCE · CREDIT_OFFICER · AUDITOR — permission matrix in `database/seed.sql`. Institution users only ever see their own institution's data; regulators see national data.

### OWASP measures

Prepared statements everywhere (no string-built SQL with user input), output escaping via `e()` helper, CSRF tokens on all state-changing requests (form + JSON header), session hardening (httponly/samesite cookies, regeneration, fixation protection), login throttling + attempt logging, security headers incl. CSP, API keys stored only as SHA-256, whitelist input validation, 403-on-missing-permission guards.

## Setup (XAMPP / Apache)

1. **Fresh install** — `schema.sql` is complete (it already contains everything from upgrades v2–v4):
   ```
   mysql -u root -p < database/schema.sql
   mysql -u root -p < database/seed.sql
   ```
   cPanel/phpMyAdmin: select your database, then import `schema.cpanel.sql` and `seed.cpanel.sql`
   (generated copies without `CREATE DATABASE`/`USE`).
   **Existing install** — back up first (`scripts/backup.sh`), then apply only the upgrades not yet applied, in order:
   `upgrade_v2.sql` → `upgrade_v3.sql` → `upgrade_v4.sql`. Never run upgrade files on a fresh schema.
2. Create `config/config.local.php` from `config.local.example.php`: DB credentials, `app.env = production`,
   a 32+ character random `app.secret` (it encrypts 2FA seeds at rest) and `app.timezone`.
3. Serve `public/` as docroot, or place the repo in htdocs and browse to `/FNCRB/public/`. When the repository root
   is the web root (cPanel), the root `.htaccess` confines requests to `public/` and refuses `app/`, `config/`,
   `database/`, `scripts/`, `storage/` and `tests/` (each also carries its own deny-all `.htaccess`).
4. Rotate all credentials before production. Issue institution API keys from **Institutions → Issue/Rotate API key**
   (shown once) or on the CLI:
   ```
   php scripts/make_api_key.php MICROBANK-PL
   ```
5. Optional: install `database/audit_immutability.sql` (append-only triggers on `audit_logs`) where the host
   grants the TRIGGER privilege.

### Demo users (password `ChangeMe!2026` — dev only)

Accounts created from the UI receive a one-time password that must be changed at first sign-in.

| Email | Role |
|---|---|
| admin@fncrb.cm | SUPER_ADMIN |
| supervisor@cobac.cm | REGULATOR |
| admin@microbank.cm | INST_ADMIN (Express Micro-bank, CAT2) |
| officer@microbank.cm | CREDIT_OFFICER |
| officer@camccul.cm | CREDIT_OFFICER (CAMCCUL, CAT1) |
| compliance@microbank.cm | COMPLIANCE |
| auditor@microbank.cm | AUDITOR |

## Machine API

Auth: signed requests (see *Machine API v1.1* below) with the institution API key.

- `POST /api/v1/loans` — batch upsert: `{"schema_version":"1.0","loans":[{...}]}` (max 5 000 records / 10 MB).
  Each record: `borrower{full_name, cni_number|niu|coop_member_id (at least one), date_of_birth?, …}` or
  `borrower{master_ref}`, plus `contract_ref`, `loan_type`, `principal_xaf`, `outstanding_xaf` (0 allowed),
  dates (`YYYY-MM-DD`), `days_past_due`, `status`, `reported_at`, optional
  `guarantors[{full_name, cni_number?, guarantee_xaf, solidarity_group?}]`.
  Records are validated and ingested independently; the response lists per-record errors. Identity conflicts
  (known identifier but different name/DOB, or identifiers pointing to two borrowers) are parked for bureau review
  (`IDENTITY_CONFLICT #n`). Re-reporting a value removed by a dispute correction is refused (`CORRECTION_REVERT`),
  as is a reporting period older than the one held.
- `POST /api/v1/inquiry` — real-time consent-gated credit check by `master_ref`/`cni_number`/`niu`/`coop_member_id`,
  with either `consent_id` (previously recorded) or `consent_ref` + `consent_type` + **`consent_signed_at`**
  (+ `consent_evidence_sha256`, mandatory for `PHYSICAL`). Returns the score with reason codes (`NR` for thin files),
  open exposure, a 24-month history summary, incidents and the `consent_id` to reuse. **412** if no valid consent.
- `POST /api/v1/incidents` — CIP feed: `{"schema_version":"1.0","incident":{borrower_identifier|borrower{…}, incident_type, instrument_ref, amount_xaf, incident_date}}`.
- `GET /api/v1/supervisory-package` — national (regulator/bureau) **session** only; institution users get their
  own institution's package from `/compliance/supervisory-package`.

## Legal note

Inquiries without recorded borrower consent expose the querying institution to COBAC sanctions and national data-privacy penalties. The system enforces this at the service layer and audits every refusal.

Provisioning rates, concentration limits and score weights are configurable illustrations of COBAC conventions — calibrate with your compliance officer before production use.

## Security & operations (regulatory hardening)

- **2FA (TOTP)**: each user enrolls under *My Account → Two-factor authentication* (RFC 6238, pure PHP, works with Google Authenticator/Authy/FreeOTP). Login then requires email + password + one-time code. Codes are single-use (replay-protected), seeds are encrypted at rest (AES-256-GCM, key derived from `app.secret`), and removing 2FA requires the password plus a code. Admins can reset a user's 2FA (lost device).
- **Sessions**: re-validated against the database on every request. Locking a user, resetting their password, changing their role, or suspending/revoking their institution ends their live sessions immediately. 30-minute idle and 12-hour absolute timeouts; logout is a CSRF-protected POST.
- **Lockout**: per-account lock after 5 failures (15 min, independent of source IP) plus a per-IP throttle against password spraying.
- **User lifecycle**: INST_ADMINs manage their institution's accounts (*Users*). SUPER_ADMIN manages all accounts and creates national (regulator/bureau) users and institution admins. Generated passwords must be changed at first sign-in.
- **Password policy**: ≥10 chars with upper/lower/digit, enforced on change and on generated credentials.
- **Ops scripts** (schedule via cron; all refuse to run over HTTP):
  - `php scripts/verify_audit_chain.php` — **nightly** full audit-chain re-verification; exits non-zero on a break
  - `php scripts/retention_purge.php` — monthly privacy retention, horizons in `config.php → retention` (consents only when no inquiry relies on them, login attempts, nonces, score snapshots, loan history, orphaned evidence files; never touches the audit chain)
  - `php scripts/late_report_monitor.php [days]` — daily BEAC/COBAC periodicity control; exits non-zero when institutions miss reporting (cron alerting)
  - `php scripts/ingest_batch.php <INST> <file.json>` — CBS batch upload (per-record validation, logged for data-quality metrics)
  - `sh scripts/backup.sh /backup/dir [user] [db]` — nightly gzipped dump (password via `MYSQL_PWD`/`~/.my.cnf`, optional GPG encryption via `FNCRB_BACKUP_GPG_RECIPIENT`, SHA-256 sidecar), keeps last 30
- **Prudential calibration**: single-borrower concentration limit configurable via `security.single_borrower_limit_pct` in `config.php` — set the official COBAC/BEAC figure.

---

## v4 — security & data-protection hardening (`upgrade_v4.sql`)

- **Institution data isolation**: borrower lists are limited to borrowers the institution reports on, registered, or
  holds an active consent for; anyone else can only be found by an exact identifier, with minimal disclosure.
  Concentration, the supervisory package, the audit trail, analytics and dispute KPIs are institution-scoped;
  national views require a national account.
- **Consent evidence**: signature date (at most 30 days old), officer attestation, uploaded signed form for physical
  consents (SHA-256 fingerprinted, stored outside the web root, integrity-checked when retrieved), consent reference
  unique per institution, revocation (*Consents*), TTL from config. An **Inquiry Log** page serves `inquiry.view.own/all`.
- **Tamper-evident audit v2**: each row's hash covers its full content; writes are serialized (DB lock, dedicated
  connection); verification is incremental on page load and full every night; a failed full verification stays flagged
  until resolved; the authorized repair tool re-hashes and records its justification.
- **Identity reconciliation queue** (*Reconciliation*, `borrower.reconcile`): name/DOB mismatches and conflicting
  identifiers are parked instead of being silently attached to someone else's file. The bureau accepts (the record is
  re-ingested onto the matched file) or rejects, and can merge duplicate records (all credit-file references repointed).
- **Single definitions**: open portfolio = ACTIVE + RESTRUCTURED; NPL = open credit more than 90 days past due
  (UNCERTAIN, DOUBTFUL, COMPROMISED). Used by the dashboard, compliance, supervisory package, analytics and macro view.
- **Credit history**: each re-report keeps the previous period in `loan_history`. The credit report shows closed credits
  and the 24-month worst delinquency. Scores add write-off and serious-delinquency factors, positive history for settled
  credits, **reason codes**, and **NR (not rated)** for thin files.
- **Disputes**: corrections are validated, limited to the disputed credit file, applied atomically with the status
  change, trigger reclassification, and cannot be undone by re-reporting. The furnisher institution sees disputes about
  its data and can respond. References are collision-free.
- **Collateral & incidents**: web forms; collateral can only secure the institution's own open loans; any RCCM
  reference already registered is refused (race-safe); release and foreclosure; incident regularization by the
  reporting institution; CIP API feed.
- **Bureau administration**: *Institutions* (onboard; suspend/revoke, which cuts sessions and API access immediately;
  net equity; IP allow-list; API key issue/rotation) and national user creation.
- **Consumer credit file** (`/borrowers/report`, `disputes.work`): everything held on a person, including who
  consulted it, for subject-access requests and dispute investigation.
- **Hardening**: strict CSP (no inline script), CSV formula-injection neutralization and checksums, valid XLSX styles,
  typed JSON errors and masked 500s in production, per-institution API nonces, body-size and batch limits, a single
  app time zone shared by PHP and the database, a deployment config that ships the root `.htaccess`, and deny-all
  `.htaccess` files in non-public directories.

## Automated tests

```
php tests/unit.php                         # pure logic, no database (35 checks)
# end-to-end against a FRESH database (schema.sql + seed.sql) — the suite mutates data, never use production:
FNCRB_DB_PORT=3306 php -S 127.0.0.1:8099 -t public public/dev_router.php &
FNCRB_DB_PORT=3306 FNCRB_TEST_URL=http://127.0.0.1:8099 php tests/smoke.php    # 118 checks
```

`FNCRB_DB_HOST/PORT/NAME/USER/PASS` environment variables override the DB configuration. `tests/smoke.php` covers
authentication, data scoping, consent, ingestion and reconciliation, collateral, incidents, disputes, user lifecycle and
session revocation, audit tamper detection and concurrency, exports, and page rendering per role.

---

## UAT — User Acceptance Test (historical record)

Latest run: **2026-09-13 · 47 checks · PASS** (scripted black-box run against a seeded environment; 10 initial script-side false negatives were re-verified manually — all pass).

### Coverage & results

| # | Area | Checks | Result |
|---|---|---|---|
| U-1 | Access control & authentication (landing, login, terms gate, bad credentials, unauth redirect) | 6 | PASS |
| U-2 | Credit officer workflows (dashboard, institution-scoped portfolio isolation, inquiry/borrower/loan forms, RBAC 403s) | 11 | PASS |
| U-3 | Consent-gated web inquiry (score render, cross-institution exposure, CIP incidents) | 3 | PASS |
| U-4 | User lifecycle (create w/ one-time password, lock blocks login, unlock, admin reset) | 5 | PASS |
| U-5 | Compliance (portfolio quality, supervisory package, concentration, provisioning run, RBAC) | 5 | PASS |
| U-6 | Auditor (chain verification page, deny inquiry/loans) | 3 | PASS |
| U-7 | Regulator national scope (all-institution loans, incidents, collateral, RBAC) | 5 | PASS |
| U-8 | API channel (401 without key, 412 without consent, supervisory 401) | 4 | PASS |
| U-9 | i18n (FR/EN login + terms) | 3 | PASS |
| U-10 | CSRF (state change without token → 419) | 1 | PASS |

### Verified security behaviors

- Password/OTP login + lockout throttling; TOTP enrollment → challenge → success/wrong-code paths
- Terms & Conditions gate enforced server-side (refusal audited)
- Institution data isolation (officer sees only own loans; regulator sees all)
- Consent-less API inquiry → **412** + `INQUIRY_REFUSED` audit; unknown API key → **401**
- OHADA double-pledge registration → **409** + `DOUBLE_PLEDGE_BLOCKED`
- Audit tamper detection: deleting a row → "CHAIN BROKEN at #N" on the Audit page
- `scripts/repair_audit_chain.php --confirm` re-links an authorized break and appends `AUDIT_CHAIN_REPAIRED`

### Regression checklist (re-run before each release)

1. `bash uat.sh`-style pass: login × 5 roles (admin, supervisor, inst admin, officer, compliance, auditor)
2. Web inquiry with consent → report renders; API inquiry without consent → 412
3. Create user → lock → login attempt fails → unlock → reset password → login OK
4. `/compliance/reclassify` returns `ok:true`; supervisory package JSON loads
5. `/audit` shows **Chain integrity verified**
6. `php scripts/late_report_monitor.php` and `php scripts/retention_purge.php` run clean
7. FR/EN switch renders on login and terms pages

---

## Machine API v1.1 — hardened channel

Every `/api/v1/*` machine request must now be **signed** (HMAC-SHA256) and carries replay protection:

| Header | Content |
|---|---|
| `X-FNCRB-Key` | institution API key (SHA-256 at rest) |
| `X-FNCRB-Timestamp` | unix seconds, accepted within ±300 s |
| `X-FNCRB-Nonce` | unique hex string (16–64 chars) per request |
| `X-FNCRB-Signature` | `hex(hmac_sha256(api_key, "<timestamp>.<nonce>.<raw body>"))` |

Enforced server-side: signature verification (constant-time), clock window, one-time nonces (DB-enforced, replay → `409`), **60 requests/minute per institution** (→ `429`), optional **per-institution IP allow-list** (`institutions.ip_allowlist`, CSV). All rejections are audited.

Payloads must declare `"schema_version":"1.0"`; responses echo it. Errors use the typed envelope:

```json
{"error": {"code": "CONSENT_REQUIRED", "message": "No valid borrower consent on record..."}}
```

Error codes: `AUTH_MISSING_KEY` · `AUTH_BAD_TIMESTAMP` · `AUTH_BAD_NONCE` · `AUTH_MISSING_SIGNATURE` · `AUTH_INVALID_KEY` · `AUTH_BAD_SIGNATURE` · `AUTH_IP_BLOCKED` · `AUTH_REPLAYED_NONCE` · `RATE_LIMITED` · `PAYLOAD_TOO_LARGE` · `INVALID_JSON` · `SCHEMA_VERSION` · `EMPTY_PAYLOAD` · `BATCH_TOO_LARGE` · `BORROWER_NOT_FOUND` · `CONSENT_INCOMPLETE` · `CONSENT_REJECTED` · `CONSENT_REQUIRED` · `INCIDENT_REJECTED` · `AUTH_REQUIRED` · `INTERNAL_ERROR`

Example (bash + openssl):

```bash
KEY=fncrb_...; BODY='{"schema_version":"1.0","cni_number":"118545678","consent_ref":"CS-1","consent_type":"DIGITAL","consent_signed_at":"2026-09-20","purpose":"underwriting"}'
TS=$(date +%s); NONCE=$(openssl rand -hex 16)
SIG=$(printf '%s.%s.%s' "$TS" "$NONCE" "$BODY" | openssl dgst -sha256 -hmac "$KEY" -hex | awk '{print $NF}')
curl -X POST https://host/api/v1/inquiry -H "X-FNCRB-Key: $KEY" -H "X-FNCRB-Timestamp: $TS" \
     -H "X-FNCRB-Nonce: $NONCE" -H "X-FNCRB-Signature: $SIG" -H "Content-Type: application/json" -d "$BODY"
```

> **PHP requirement**: the XLSX export engine needs the `zip` extension (`extension=zip` in php.ini).
> **Upgrade note**: apply `database/upgrade_v2.sql` (api_nonces table + ip_allowlist column); existing plain-key API clients must be migrated to signed requests.
> **v4 API changes**: `/api/v1/loans` requires the `"loans"` array; new consents need `consent_signed_at` (and `consent_evidence_sha256` for PHYSICAL); regulator institutions have no machine channel.

## Report exports

- `GET /reports/loans.csv` · `/reports/loans.xlsx` — portfolio return (RBAC-scoped: own institution or national for regulators)
- `GET /reports/supervisory.xlsx` — COBAC asset-classification package (compliance roles)
- `GET /reports/incidents.csv` — CIP incidents
- CSV and XLSX downloads carry an `X-Report-SHA256` integrity header; every export is audited (`REPORT_EXPORT`, with the checksum). Text cells are neutralized against spreadsheet formula injection.
- The web credit report has a **Print / Save as PDF** action (print stylesheet).
- `php scripts/generate_monthly_reports.php` — monthly per-institution XLSX returns into `storage/reports/` with SHA-256 checksums, audited (cron: `5 0 1 * *`).

---

## Four-pillar analytics & consumer rights (upgrade v3)

Apply `database/upgrade_v3.sql` (ingestion_log, disputes, data_corrections + new permissions: `disputes.file`, `disputes.work`, `analytics.view`).

**1 · Data Ingestion & Quality Analytics** (`/analytics`) — per-provider scorecard: **accuracy** (submission rejection rate from `ingestion_log`, written on every API batch), **completeness** (COBAC-standard field population), **timeliness** (reporting freshness vs 35-day threshold) and a composite **quality score/grade** (40/30/30) + identity-reconciliation queue count.

**2 · Credit Bureau Operations & Inquiry Metrics** — inquiry volume (30-day trend chart), demand ranking by institution, channel mix (WEB/API), unique borrowers queried, active subscribers, and **consent-compliance rate** (inquiries vs audited refusals).

**3 · Registry & System Performance** — signed API throughput per hour (24h), audit-event heartbeat (14d), API security rejections, failed-logins/throttle events, 2FA adoption, audit-chain integrity status, and registry data inventory.

**4 · Consumer Rights & Dispute Management** (`/disputes`) — statutory workflow per Law 2010/012: institutions **file disputes** on behalf of consumers (types: inaccurate balance, wrong classification, not-my-loan, duplicate identity, stale data) with a **30-day SLA**; bureau staff/regulators **review → correct or reject**. Corrections apply whitelisted field changes to `loans`/`borrowers` with an old/new **evidence trail** (`data_corrections`) and full audit logging. Illegal status transitions are rejected server-side; over-SLA cases are highlighted.

All four sections render on `/analytics` (charts + KPI tiles) and as JSON at `/analytics.json`. RBAC: officers/compliance file disputes; regulators/super-admin work them; analytics visible to admins/compliance/regulators.

## 5 · Macro-Financial & Credit Market Analytics (strategic executive view)

Regulator/SUPER_ADMIN-only section on `/analytics` (institution users get `macro: null` in JSON and no UI section). Aggregated, anonymized system-wide intelligence:

- **NPL indicator** — national and sectoral share of open accounts more than 90 days past due (count-based and value-based ratios).
- **Credit coverage ratio** — individuals in registry vs adult population, legal entities vs registered companies (denominators configurable in `config.php → macro`; calibrate with BEAC/INS statistics).
- **Credit growth rate** — new credit facilities per month (25-month series), MoM and YoY growth, plus MoM growth by sector (green growth / red contraction).
- **Indebtedness index** — average active accounts per borrower, average debt burden (XAF), accounts distribution (1 / 2 / 3+), share of multi-institution borrowers, and over-indebtedness watch (3+ accounts or institutions).
