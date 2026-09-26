-- cPanel/phpMyAdmin variant: GENERATED from schema.sql (no CREATE DATABASE / USE).
-- Select your database first, then import. Do not edit by hand — edit schema.sql and regenerate.
-- =====================================================================
-- FNCRB — First National Credit Registry Bureau
-- Central Credit Registry (Cameroon / CEMAC — COBAC, BEAC, CNEF, OHADA)
-- MySQL 8 / MariaDB 10.4+ schema — COMPLETE for fresh installs
-- (includes everything from upgrade_v2, upgrade_v3 and upgrade_v4).
-- Existing installations: apply the upgrade_v*.sql files in order instead.
-- =====================================================================

SET NAMES utf8mb4;

-- ---------------------------------------------------------------------
-- Institutions (Category 1/2/3 MFIs, banks, regulator)
-- ---------------------------------------------------------------------
CREATE TABLE institutions (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    code          VARCHAR(20)  NOT NULL UNIQUE,
    name          VARCHAR(200) NOT NULL,
    category      ENUM('CAT1','CAT2','CAT3','BANK','REGULATOR') NOT NULL,
    legal_form    VARCHAR(100) NULL,
    rccm_number   VARCHAR(50)  NULL,
    niu           VARCHAR(50)  NULL,
    head_office   VARCHAR(200) NULL,
    net_equity_xaf BIGINT UNSIGNED NOT NULL DEFAULT 0,
    status        ENUM('ACTIVE','SUSPENDED','REVOKED') NOT NULL DEFAULT 'ACTIVE',
    api_key_hash  CHAR(64)     NULL,           -- SHA-256 of API key
    ip_allowlist  TEXT         NULL,           -- machine-API IP allow-list (CSV, NULL = open)
    created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- RBAC: roles, permissions, users
-- ---------------------------------------------------------------------
CREATE TABLE roles (
    id   SMALLINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(40) NOT NULL UNIQUE,
    name VARCHAR(100) NOT NULL,
    description VARCHAR(255) NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE permissions (
    id   SMALLINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(60) NOT NULL UNIQUE,
    description VARCHAR(255) NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE role_permissions (
    role_id       SMALLINT UNSIGNED NOT NULL,
    permission_id SMALLINT UNSIGNED NOT NULL,
    PRIMARY KEY (role_id, permission_id),
    FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE,
    FOREIGN KEY (permission_id) REFERENCES permissions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE users (
    id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    institution_id INT UNSIGNED NULL,          -- NULL => national (bureau / COBAC / BEAC)
    role_id        SMALLINT UNSIGNED NOT NULL,
    full_name      VARCHAR(150) NOT NULL,
    email          VARCHAR(190) NOT NULL UNIQUE,
    password_hash  VARCHAR(255) NOT NULL,
    officer_level  TINYINT UNSIGNED NOT NULL DEFAULT 1,  -- 1 junior, 2 senior, 3 manager
    branch_code    VARCHAR(30) NULL,
    status         ENUM('ACTIVE','LOCKED','DISABLED') NOT NULL DEFAULT 'ACTIVE',
    failed_logins  TINYINT UNSIGNED NOT NULL DEFAULT 0,    -- consecutive failures (account lockout)
    locked_until   DATETIME NULL,
    must_change_password TINYINT(1) NOT NULL DEFAULT 0,    -- set for generated one-time passwords
    session_version INT UNSIGNED NOT NULL DEFAULT 1,       -- bump to revoke all sessions
    totp_secret    VARCHAR(255) NULL,                      -- 2FA seed, encrypted at rest (enc:v1:)
    totp_last_step BIGINT UNSIGNED NULL,                   -- last accepted TOTP step (replay guard)
    last_login_at  DATETIME NULL,
    created_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_users_inst FOREIGN KEY (institution_id) REFERENCES institutions(id),
    CONSTRAINT fk_users_role FOREIGN KEY (role_id) REFERENCES roles(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Borrowers + identity reconciliation (CNI / NIU / cooperative ID)
-- ---------------------------------------------------------------------
CREATE TABLE borrowers (
    id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    master_ref     CHAR(12)     NOT NULL UNIQUE,        -- FNCRB unique borrower ref
    full_name      VARCHAR(200) NOT NULL,
    date_of_birth  DATE NULL,
    gender         ENUM('M','F') NULL,
    type           ENUM('INDIVIDUAL','CORPORATE') NOT NULL DEFAULT 'INDIVIDUAL',
    cni_number     VARCHAR(30)  NULL,   -- Carte Nationale d'Identité
    niu            VARCHAR(30)  NULL,   -- Numéro d'Identification Unique (tax)
    coop_member_id VARCHAR(40)  NULL,   -- cooperative member id
    phone          VARCHAR(25)  NULL,
    region         VARCHAR(60)  NULL,
    dup_of_id      INT UNSIGNED NULL,   -- reconciliation pointer
    created_by_inst_id INT UNSIGNED NULL, -- registering institution (visibility scope)
    created_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (dup_of_id) REFERENCES borrowers(id),
    CONSTRAINT fk_borrowers_created_by FOREIGN KEY (created_by_inst_id) REFERENCES institutions(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE UNIQUE INDEX uq_borrower_cni ON borrowers (cni_number);
CREATE UNIQUE INDEX uq_borrower_niu ON borrowers (niu);
CREATE INDEX idx_borrower_coop ON borrowers (coop_member_id);

-- ---------------------------------------------------------------------
-- Loans / credit portfolio (ingested from CBS, COBAC-standardized)
-- ---------------------------------------------------------------------
CREATE TABLE loans (
    id                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    institution_id    INT UNSIGNED NOT NULL,
    borrower_id       INT UNSIGNED NOT NULL,
    contract_ref      VARCHAR(50)  NOT NULL,
    loan_type         ENUM('CONSUMER','MORTGAGE','BUSINESS','MICRO','PROJECT','OVERDRAFT','LEASE') NOT NULL,
    currency          CHAR(3) NOT NULL DEFAULT 'XAF',
    principal_xaf     BIGINT UNSIGNED NOT NULL,
    outstanding_xaf   BIGINT UNSIGNED NOT NULL,
    monthly_payment_xaf BIGINT UNSIGNED NOT NULL DEFAULT 0,
    interest_rate_pct DECIMAL(6,3) NOT NULL DEFAULT 0,
    start_date        DATE NOT NULL,
    maturity_date     DATE NOT NULL,
    instalments_total SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    instalments_past_due SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    days_past_due     INT UNSIGNED NOT NULL DEFAULT 0,
    -- COBAC asset classification (computed, materialized for reporting)
    cobac_class       ENUM('HEALTHY','WATCH','UNCERTAIN','DOUBTFUL','COMPROMISED') NOT NULL DEFAULT 'HEALTHY',
    provision_xaf     BIGINT UNSIGNED NOT NULL DEFAULT 0,
    status            ENUM('ACTIVE','SETTLED','WRITTEN_OFF','RESTRUCTURED') NOT NULL DEFAULT 'ACTIVE',
    reported_at       DATE NOT NULL,           -- reporting period
    source            ENUM('BATCH','API','MANUAL') NOT NULL DEFAULT 'API',
    created_at        TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at        TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_loan (institution_id, contract_ref),
    FOREIGN KEY (institution_id) REFERENCES institutions(id),
    FOREIGN KEY (borrower_id) REFERENCES borrowers(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX idx_loans_borrower ON loans (borrower_id);
CREATE INDEX idx_loans_inst_period ON loans (institution_id, reported_at);
CREATE INDEX idx_loans_status_class ON loans (status, cobac_class);

-- Month-over-month reporting history (payment history / 24-month delinquency)
CREATE TABLE loan_history (
    id                   BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    loan_id              INT UNSIGNED NOT NULL,
    reported_at          DATE NOT NULL,
    outstanding_xaf      BIGINT UNSIGNED NOT NULL,
    days_past_due        INT UNSIGNED NOT NULL,
    instalments_past_due SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    cobac_class          ENUM('HEALTHY','WATCH','UNCERTAIN','DOUBTFUL','COMPROMISED') NOT NULL,
    status               ENUM('ACTIVE','SETTLED','WRITTEN_OFF','RESTRUCTURED') NOT NULL,
    captured_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_loan_period (loan_id, reported_at),
    FOREIGN KEY (loan_id) REFERENCES loans(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Repayment schedules / arrears detail (reserved for schedule-level feeds)
CREATE TABLE repayments (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    loan_id    INT UNSIGNED NOT NULL,
    due_date   DATE NOT NULL,
    amount_due_xaf BIGINT UNSIGNED NOT NULL,
    amount_paid_xaf BIGINT UNSIGNED NOT NULL DEFAULT 0,
    paid_date  DATE NULL,
    status     ENUM('PENDING','PAID','PARTIAL','MISSED') NOT NULL DEFAULT 'PENDING',
    FOREIGN KEY (loan_id) REFERENCES loans(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Module 3: OHADA collateral (sûretés) & RCCM cross-reference
-- ---------------------------------------------------------------------
CREATE TABLE collateral (
    id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    loan_id        INT UNSIGNED NOT NULL,
    institution_id INT UNSIGNED NOT NULL,
    collateral_type ENUM('NANTISSEMENT_EQUIPMENT','NANTISSEMENT_BUSINESS','VEHICLE_MORTGAGE','IMMOVABLE_MORTGAGE','PLEDGE','OTHER') NOT NULL,
    description    VARCHAR(255) NOT NULL,
    estimated_value_xaf BIGINT UNSIGNED NOT NULL DEFAULT 0,
    rccm_registration_no VARCHAR(50) NULL,   -- Registre du Commerce et du Crédit Mobilier ref
    rccm_registered_at DATE NULL,
    status         ENUM('REGISTERED','RELEASED','FORECLOSED') NOT NULL DEFAULT 'REGISTERED',
    released_at    DATETIME NULL,
    released_by    INT UNSIGNED NULL,
    created_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (loan_id) REFERENCES loans(id) ON DELETE CASCADE,
    FOREIGN KEY (institution_id) REFERENCES institutions(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX idx_collateral_rccm ON collateral (rccm_registration_no, status);

-- Guarantors (cautionnement) & solidarity groups (cross-liability)
CREATE TABLE guarantors (
    id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    borrower_id    INT UNSIGNED NULL,      -- guarantor resolved to a registry borrower if known
    full_name      VARCHAR(200) NOT NULL,
    cni_number     VARCHAR(30) NULL,
    loan_id        INT UNSIGNED NOT NULL,
    guarantee_xaf  BIGINT UNSIGNED NOT NULL DEFAULT 0,
    solidarity_group VARCHAR(60) NULL,     -- group-lending solidarity bond id
    created_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (loan_id) REFERENCES loans(id) ON DELETE CASCADE,
    FOREIGN KEY (borrower_id) REFERENCES borrowers(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Module 2: payment incidents (CNEF / CIP feed)
-- ---------------------------------------------------------------------
CREATE TABLE payment_incidents (
    id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    institution_id INT UNSIGNED NOT NULL,
    borrower_id    INT UNSIGNED NOT NULL,
    incident_type  ENUM('BOUNCED_CHEQUE','DEFAULTED_NOTE','UNAUTHORIZED_OVERDRAFT','FRAUD_INSTRUMENT') NOT NULL,
    instrument_ref VARCHAR(60) NOT NULL,
    amount_xaf     BIGINT UNSIGNED NOT NULL,
    incident_date  DATE NOT NULL,
    resolved       TINYINT(1) NOT NULL DEFAULT 0,
    resolved_at    DATE NULL,
    resolution_note VARCHAR(255) NULL,
    resolved_by    INT UNSIGNED NULL,
    created_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (institution_id) REFERENCES institutions(id),
    FOREIGN KEY (borrower_id) REFERENCES borrowers(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX idx_incident_instrument ON payment_incidents (institution_id, instrument_ref);
CREATE INDEX idx_incident_borrower ON payment_incidents (borrower_id, resolved);

-- Nonce replay protection + request-rate metering (one signed request = one nonce)
CREATE TABLE api_nonces (
    nonce          CHAR(64)     NOT NULL,
    institution_id INT UNSIGNED NOT NULL,
    ip_address     VARCHAR(45)  NULL,
    created_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (institution_id, nonce),
    INDEX idx_nonce_inst_time (institution_id, created_at),
    FOREIGN KEY (institution_id) REFERENCES institutions(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Module 5: consent management + inquiry log + tamper-evident audit
-- ---------------------------------------------------------------------
CREATE TABLE consents (
    id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    borrower_id    INT UNSIGNED NOT NULL,
    institution_id INT UNSIGNED NOT NULL,
    consent_type   ENUM('DIGITAL','PHYSICAL') NOT NULL,
    consent_ref    VARCHAR(80) NOT NULL,    -- signature / document reference (unique per institution)
    scope          ENUM('CREDIT_CHECK','FULL_REPORT') NOT NULL DEFAULT 'CREDIT_CHECK',
    signed_at      DATE NULL,               -- date the borrower signed
    granted_at     DATETIME NOT NULL,       -- date recorded in the registry
    expires_at     DATETIME NOT NULL,
    evidence_sha256 CHAR(64) NULL,          -- fingerprint of the signed form / e-signature artefact
    evidence_path  VARCHAR(255) NULL,       -- storage/consents/... (web channel uploads)
    captured_by    INT UNSIGNED NULL,       -- user who recorded it (NULL = API)
    revoked_at     DATETIME NULL,
    revoked_by     INT UNSIGNED NULL,
    revoke_reason  VARCHAR(255) NULL,
    INDEX idx_consent_ref (institution_id, consent_ref),
    INDEX idx_consent_borrower (borrower_id, institution_id, expires_at),
    FOREIGN KEY (borrower_id) REFERENCES borrowers(id),
    FOREIGN KEY (institution_id) REFERENCES institutions(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE inquiry_logs (
    id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    institution_id INT UNSIGNED NOT NULL,
    user_id        INT UNSIGNED NULL,
    borrower_id    INT UNSIGNED NOT NULL,
    consent_id     INT UNSIGNED NULL,
    channel        ENUM('WEB','API') NOT NULL,
    purpose        VARCHAR(255) NULL,
    created_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_inquiry_inst_time (institution_id, created_at),
    INDEX idx_inquiry_borrower (borrower_id, created_at),
    FOREIGN KEY (institution_id) REFERENCES institutions(id),
    FOREIGN KEY (borrower_id) REFERENCES borrowers(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Hash-chained, append-only audit trail (see app/Core/Audit.php)
CREATE TABLE audit_logs (
    id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id      INT UNSIGNED NULL,
    institution_id INT UNSIGNED NULL,
    action       VARCHAR(60) NOT NULL,     -- LOGIN, INQUIRY, DATA_WRITE, REPORT_EXPORT ...
    entity       VARCHAR(40) NULL,
    entity_id    VARCHAR(30) NULL,
    details      JSON NULL,
    ip_address   VARCHAR(45) NULL,
    prev_hash    CHAR(64) NULL,
    row_hash     CHAR(64) NOT NULL,
    hash_version TINYINT UNSIGNED NOT NULL DEFAULT 1,  -- 2 = full-content hash (verifiable)
    ts_ms        BIGINT UNSIGNED NULL,                 -- hashed timestamp (ms, UTC epoch)
    created_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX idx_audit_created ON audit_logs (created_at);
CREATE INDEX idx_audit_inst ON audit_logs (institution_id, id);
CREATE INDEX idx_audit_action ON audit_logs (action, created_at);

-- Last fully verified position of the audit chain (incremental verification)
CREATE TABLE audit_checkpoints (
    id          TINYINT UNSIGNED NOT NULL PRIMARY KEY,
    last_id     BIGINT UNSIGNED NOT NULL,
    last_hash   CHAR(64) NOT NULL,
    verified_at DATETIME NOT NULL,
    broken_at   BIGINT UNSIGNED NULL,     -- sticky failure from the last full verification
    broken_reason VARCHAR(255) NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Scoring snapshots (systemic scoring engine output)
-- ---------------------------------------------------------------------
CREATE TABLE score_snapshots (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    borrower_id   INT UNSIGNED NOT NULL,
    score         SMALLINT UNSIGNED NOT NULL,   -- 300-850 style
    risk_grade    ENUM('A','B','C','D','E') NOT NULL,
    dti_pct       DECIMAL(6,2) NULL,
    input_factors JSON NULL,
    computed_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (borrower_id) REFERENCES borrowers(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Login throttle (OWASP ASVS 2.2)
CREATE TABLE login_attempts (
    id         BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    email      VARCHAR(190) NOT NULL,
    ip_address VARCHAR(45)  NOT NULL,
    success    TINYINT(1) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_la_email_ip (email, ip_address, created_at),
    INDEX idx_la_ip (ip_address, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Data quality, disputes, identity reconciliation
-- ---------------------------------------------------------------------
CREATE TABLE ingestion_log (
    id             BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    institution_id INT UNSIGNED NOT NULL,
    source         ENUM('API','BATCH','MANUAL') NOT NULL,
    submitted      INT UNSIGNED NOT NULL DEFAULT 0,   -- records submitted
    accepted       INT UNSIGNED NOT NULL DEFAULT 0,
    rejected       INT UNSIGNED NOT NULL DEFAULT 0,
    created_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (institution_id) REFERENCES institutions(id),
    INDEX idx_ing_inst_time (institution_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE disputes (
    id                 INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    reference          CHAR(14) NOT NULL UNIQUE,          -- DSP-YYYY-XXXXX
    borrower_id        INT UNSIGNED NOT NULL,
    filed_by_inst_id   INT UNSIGNED NOT NULL,             -- institution filing on behalf of the consumer
    against_inst_id    INT UNSIGNED NULL,                 -- institution whose data is disputed (NULL = registry-wide)
    dispute_type       ENUM('INACCURATE_BALANCE','WRONG_CLASSIFICATION','NOT_MY_LOAN','DUPLICATE_IDENTITY','STALE_DATA','OTHER') NOT NULL,
    loan_id            INT UNSIGNED NULL,
    details            TEXT NOT NULL,
    status             ENUM('OPEN','UNDER_REVIEW','CORRECTED','REJECTED','WITHDRAWN') NOT NULL DEFAULT 'OPEN',
    furnisher_response TEXT NULL,
    furnisher_responded_at DATETIME NULL,
    furnisher_responded_by INT UNSIGNED NULL,
    resolution_note    TEXT NULL,
    sla_due_at         DATETIME NOT NULL,                 -- statutory response window (30 days)
    resolved_by        INT UNSIGNED NULL,
    resolved_at        DATETIME NULL,
    created_at         TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (borrower_id) REFERENCES borrowers(id),
    FOREIGN KEY (filed_by_inst_id) REFERENCES institutions(id),
    FOREIGN KEY (against_inst_id) REFERENCES institutions(id),
    FOREIGN KEY (loan_id) REFERENCES loans(id),
    FOREIGN KEY (resolved_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX idx_disputes_status ON disputes (status, sla_due_at);

CREATE TABLE data_corrections (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    dispute_id  INT UNSIGNED NOT NULL,
    entity      VARCHAR(40) NOT NULL,      -- loans / borrowers / payment_incidents
    entity_id   INT UNSIGNED NOT NULL,
    field       VARCHAR(60) NOT NULL,
    old_value   TEXT NULL,
    new_value   TEXT NULL,
    corrected_by INT UNSIGNED NOT NULL,
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_corr_entity (entity, entity_id, created_at),
    FOREIGN KEY (dispute_id) REFERENCES disputes(id),
    FOREIGN KEY (corrected_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Submissions whose identity did not reconcile cleanly (bureau review queue)
CREATE TABLE identity_conflicts (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    institution_id      INT UNSIGNED NULL,
    source              ENUM('API','BATCH','MANUAL') NOT NULL,
    conflict_type       ENUM('MULTIPLE_MATCH','NAME_MISMATCH','DOB_MISMATCH') NOT NULL,
    matched_borrower_id INT UNSIGNED NULL,
    submitted           JSON NOT NULL,       -- borrower block as submitted
    context             JSON NULL,           -- parked record (loan / incident) for re-ingestion
    message             VARCHAR(255) NOT NULL,
    status              ENUM('OPEN','ACCEPTED','REJECTED') NOT NULL DEFAULT 'OPEN',
    decided_by          INT UNSIGNED NULL,
    decided_at          DATETIME NULL,
    decision_note       VARCHAR(255) NULL,
    created_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_conflict_status (status, created_at),
    INDEX idx_conflict_inst (institution_id, status),
    FOREIGN KEY (institution_id) REFERENCES institutions(id),
    FOREIGN KEY (matched_borrower_id) REFERENCES borrowers(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
