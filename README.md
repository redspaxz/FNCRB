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

1. Import schema + seed:
   ```
   mysql -u root -p < database/schema.sql
   mysql -u root -p < database/seed.sql
   ```
2. Adjust `config/config.php` (DB credentials, `secret`, `base_url`).
3. Serve `public/` as docroot, or place the repo in htdocs and browse to `/FNCRB/public/`.
4. Rotate all credentials before production. Generate institution API keys:
   ```
   php scripts/make_api_key.php MICROBANK-PL
   ```

### Demo users (password `ChangeMe!2026` — dev only)

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

Auth: `X-FNCRB-Key: <institution api key>` (generate via `scripts/make_api_key.php`).

- `POST /api/v1/loans` — batch upsert portfolio (`{"loans":[{...}]}`; each record: `borrower{full_name,cni_number,niu,coop_member_id}`, `contract_ref`, `loan_type`, amounts, dates, `days_past_due`, `reported_at`).
- `POST /api/v1/inquiry` — real-time consent-gated credit check (`cni_number`/`niu`/`master_ref`/`borrower_id` + `consent_ref` + `consent_type`; returns score, cross-institution exposure, incidents). Responds **412** if no valid consent — inquiry is refused and audited.
- `GET /api/v1/supervisory-package` — regulator session only.

```bash
curl -X POST http://localhost/FNCRB/public/api/v1/inquiry \
  -H "X-FNCRB-Key: fncrb_..." -H "Content-Type: application/json" \
  -d '{"cni_number":"118545678","consent_ref":"CS-2026-101","consent_type":"DIGITAL","purpose":"underwriting"}'
```

## Legal note

Inquiries without recorded borrower consent expose the querying institution to COBAC sanctions and national data-privacy penalties. The system enforces this at the service layer and audits every refusal.

Provisioning rates, concentration limits and score weights are configurable illustrations of COBAC conventions — calibrate with your compliance officer before production use.

## Security & operations (regulatory hardening)

- **2FA (TOTP)**: each user enrolls under *My Account → Two-factor authentication* (RFC 6238, pure PHP, works with Google Authenticator/Authy/FreeOTP). Login then requires email + password + one-time code; failures are throttled and audited.
- **User lifecycle**: INST_ADMINs manage their institution's accounts (*Users*): create with generated one-time passwords, lock/unlock, admin password reset. SUPER_ADMIN can see all (`/users?all`).
- **Password policy**: ≥10 chars with upper/lower/digit, enforced on change and on generated credentials.
- **Ops scripts** (schedule via cron):
  - `php scripts/retention_purge.php` — monthly privacy retention (consents >12m past expiry, login attempts >6m, score snapshots >24m; never touches the audit chain)
  - `php scripts/late_report_monitor.php [days]` — daily BEAC/COBAC periodicity control; exits non-zero when institutions miss reporting (cron alerting)
  - `sh scripts/backup.sh /backup/dir [user] [pass]` — nightly gzipped dump, keeps last 30
- **Prudential calibration**: single-borrower concentration limit configurable via `security.single_borrower_limit_pct` in `config.php` — set the official COBAC/BEAC figure.

---

## UAT — User Acceptance Test

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

Error codes: `AUTH_MISSING_KEY` · `AUTH_BAD_TIMESTAMP` · `AUTH_BAD_NONCE` · `AUTH_MISSING_SIGNATURE` · `AUTH_INVALID_KEY` · `AUTH_BAD_SIGNATURE` · `AUTH_IP_BLOCKED` · `AUTH_REPLAYED_NONCE` · `RATE_LIMITED` · `SCHEMA_VERSION` · `EMPTY_PAYLOAD` · `BORROWER_NOT_FOUND` · `CONSENT_REQUIRED` · `AUTH_REQUIRED`

Example (bash + openssl):

```bash
KEY=fncrb_...; BODY='{"schema_version":"1.0","cni_number":"118545678","consent_ref":"CS-1","consent_type":"DIGITAL"}'
TS=$(date +%s); NONCE=$(openssl rand -hex 16)
SIG=$(printf '%s.%s.%s' "$TS" "$NONCE" "$BODY" | openssl dgst -sha256 -hmac "$KEY" -hex | awk '{print $NF}')
curl -X POST https://host/api/v1/inquiry -H "X-FNCRB-Key: $KEY" -H "X-FNCRB-Timestamp: $TS" \
     -H "X-FNCRB-Nonce: $NONCE" -H "X-FNCRB-Signature: $SIG" -H "Content-Type: application/json" -d "$BODY"
```

> **PHP requirement**: the XLSX export engine needs the `zip` extension (`extension=zip` in php.ini).
> **Upgrade note**: apply `database/upgrade_v2.sql` (api_nonces table + ip_allowlist column); existing plain-key API clients must be migrated to signed requests.

## Report exports

- `GET /reports/loans.csv` · `/reports/loans.xlsx` — portfolio return (RBAC-scoped: own institution or national for regulators)
- `GET /reports/supervisory.xlsx` — COBAC asset-classification package (compliance roles)
- `GET /reports/incidents.csv` — CIP incidents
- XLSX downloads carry an `X-Report-SHA256` integrity header; every export is audited (`REPORT_EXPORT`).
- The web credit report has a **Print / Save as PDF** action (print stylesheet).
- `php scripts/generate_monthly_reports.php` — monthly per-institution XLSX returns into `storage/reports/` with SHA-256 checksums, audited (cron: `5 0 1 * *`).
