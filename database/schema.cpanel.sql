-- =====================================================================
-- FNCRB — First National Credit Registry Bureau
-- Central Credit Registry (Cameroon / CEMAC — COBAC, BEAC, CNEF, OHADA)
-- MySQL 8 schema
-- =====================================================================


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
    created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- RBAC: roles, permissions, users
-- ---------------------------------------------------------------------
CREATE TABLE roles (
    id   SMALLINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(40) NOT NULL UNIQUE,
    name VARCHAR(100) NOT NULL,
    description VARCHAR(255) NULL
) ENGINE=InnoDB;

CREATE TABLE permissions (
    id   SMALLINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(60) NOT NULL UNIQUE,
    description VARCHAR(255) NULL
) ENGINE=InnoDB;

CREATE TABLE role_permissions (
    role_id       SMALLINT UNSIGNED NOT NULL,
    permission_id SMALLINT UNSIGNED NOT NULL,
    PRIMARY KEY (role_id, permission_id),
    FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE,
    FOREIGN KEY (permission_id) REFERENCES permissions(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE users (
    id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    institution_id INT UNSIGNED NULL,          -- NULL => regulator-level (COBAC/BEAC)
    role_id        SMALLINT UNSIGNED NOT NULL,
    full_name      VARCHAR(150) NOT NULL,
    email          VARCHAR(190) NOT NULL UNIQUE,
    password_hash  VARCHAR(255) NOT NULL,
    officer_level  TINYINT UNSIGNED NOT NULL DEFAULT 1,  -- 1 junior, 2 senior, 3 manager
    branch_code    VARCHAR(30) NULL,
    status         ENUM('ACTIVE','LOCKED','DISABLED') NOT NULL DEFAULT 'ACTIVE',
    failed_logins  TINYINT UNSIGNED NOT NULL DEFAULT 0,
    locked_until   DATETIME NULL,
    totp_secret    VARCHAR(64) NULL,           -- optional 2FA
    last_login_at  DATETIME NULL,
    created_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

ALTER TABLE users
  ADD CONSTRAINT fk_users_inst FOREIGN KEY (institution_id) REFERENCES institutions(id),
  ADD CONSTRAINT fk_users_role FOREIGN KEY (role_id) REFERENCES roles(id);

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
    created_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (dup_of_id) REFERENCES borrowers(id)
) ENGINE=InnoDB;

CREATE UNIQUE INDEX uq_borrower_cni ON borrowers (cni_number);
CREATE UNIQUE INDEX uq_borrower_niu ON borrowers (niu);

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
) ENGINE=InnoDB;

CREATE INDEX idx_loans_borrower ON loans (borrower_id);
CREATE INDEX idx_loans_inst_period ON loans (institution_id, reported_at);

-- Repayment schedules / arrears detail
CREATE TABLE repayments (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    loan_id    INT UNSIGNED NOT NULL,
    due_date   DATE NOT NULL,
    amount_due_xaf BIGINT UNSIGNED NOT NULL,
    amount_paid_xaf BIGINT UNSIGNED NOT NULL DEFAULT 0,
    paid_date  DATE NULL,
    status     ENUM('PENDING','PAID','PARTIAL','MISSED') NOT NULL DEFAULT 'PENDING',
    FOREIGN KEY (loan_id) REFERENCES loans(id) ON DELETE CASCADE
) ENGINE=InnoDB;

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
    created_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (loan_id) REFERENCES loans(id) ON DELETE CASCADE,
    FOREIGN KEY (institution_id) REFERENCES institutions(id)
) ENGINE=InnoDB;

CREATE INDEX idx_collateral_rccm ON collateral (rccm_registration_no);

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
) ENGINE=InnoDB;

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
    created_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (institution_id) REFERENCES institutions(id),
    FOREIGN KEY (borrower_id) REFERENCES borrowers(id)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- Module 5: consent management + inquiry log + tamper-proof audit
-- ---------------------------------------------------------------------
CREATE TABLE consents (
    id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    borrower_id    INT UNSIGNED NOT NULL,
    institution_id INT UNSIGNED NOT NULL,
    consent_type   ENUM('DIGITAL','PHYSICAL') NOT NULL,
    consent_ref    VARCHAR(80) NOT NULL,    -- signature / document reference
    scope          ENUM('CREDIT_CHECK','FULL_REPORT') NOT NULL DEFAULT 'CREDIT_CHECK',
    granted_at     DATETIME NOT NULL,
    expires_at     DATETIME NOT NULL,
    FOREIGN KEY (borrower_id) REFERENCES borrowers(id),
    FOREIGN KEY (institution_id) REFERENCES institutions(id)
) ENGINE=InnoDB;

CREATE TABLE inquiry_logs (
    id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    institution_id INT UNSIGNED NOT NULL,
    user_id        INT UNSIGNED NULL,
    borrower_id    INT UNSIGNED NOT NULL,
    consent_id     INT UNSIGNED NULL,
    channel        ENUM('WEB','API') NOT NULL,
    purpose        VARCHAR(255) NULL,
    created_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (institution_id) REFERENCES institutions(id),
    FOREIGN KEY (borrower_id) REFERENCES borrowers(id)
) ENGINE=InnoDB;

-- Hash-chained, append-only audit trail
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
    created_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE INDEX idx_audit_created ON audit_logs (created_at);

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
) ENGINE=InnoDB;

-- Login throttle (OWASP ASVS 6.2)
CREATE TABLE login_attempts (
    id         BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    email      VARCHAR(190) NOT NULL,
    ip_address VARCHAR(45)  NOT NULL,
    success    TINYINT(1) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_la_email_ip (email, ip_address, created_at)
) ENGINE=InnoDB;
