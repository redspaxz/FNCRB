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
