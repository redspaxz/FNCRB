-- =====================================================================
-- FNCRB upgrade v4 — security & data-protection hardening
-- For EXISTING installations already at v3 (schema.sql + upgrade_v2 + upgrade_v3).
-- Fresh installs: schema.sql + seed.sql already include all of this.
--
-- Apply against the FNCRB database, e.g.
--   mysql -u root fncrb < database/upgrade_v4.sql
-- (cPanel/phpMyAdmin: select the database first, then import this file.)
-- Take a backup first (scripts/backup.sh). Run once.
-- =====================================================================
SET NAMES utf8mb4;

-- Sessions / authentication: forced password change, global session revocation,
-- encrypted TOTP seeds (longer column) and TOTP replay protection
ALTER TABLE users
  ADD COLUMN must_change_password TINYINT(1) NOT NULL DEFAULT 0 AFTER locked_until,
  ADD COLUMN session_version INT UNSIGNED NOT NULL DEFAULT 1 AFTER must_change_password,
  MODIFY COLUMN totp_secret VARCHAR(255) NULL,
  ADD COLUMN totp_last_step BIGINT UNSIGNED NULL AFTER totp_secret;

-- Borrower visibility scope (registering institution)
ALTER TABLE borrowers
  ADD COLUMN created_by_inst_id INT UNSIGNED NULL AFTER dup_of_id,
  ADD CONSTRAINT fk_borrowers_created_by FOREIGN KEY (created_by_inst_id) REFERENCES institutions(id),
  ADD INDEX idx_borrower_coop (coop_member_id);

ALTER TABLE loans ADD INDEX idx_loans_status_class (status, cobac_class);

CREATE TABLE IF NOT EXISTS loan_history (
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

-- Collateral release / foreclosure lifecycle
ALTER TABLE collateral
  ADD COLUMN released_at DATETIME NULL AFTER status,
  ADD COLUMN released_by INT UNSIGNED NULL AFTER released_at,
  DROP INDEX idx_collateral_rccm,
  ADD INDEX idx_collateral_rccm (rccm_registration_no, status);

-- Payment incident regularization
ALTER TABLE payment_incidents
  ADD COLUMN resolution_note VARCHAR(255) NULL AFTER resolved_at,
  ADD COLUMN resolved_by INT UNSIGNED NULL AFTER resolution_note,
  ADD INDEX idx_incident_instrument (institution_id, instrument_ref),
  ADD INDEX idx_incident_borrower (borrower_id, resolved);

-- Nonces are unique per institution (one client can no longer block another's nonce)
ALTER TABLE api_nonces DROP PRIMARY KEY, ADD PRIMARY KEY (institution_id, nonce);

-- Consent evidence, signature date, revocation
ALTER TABLE consents
  ADD COLUMN signed_at DATE NULL AFTER scope,
  ADD COLUMN evidence_sha256 CHAR(64) NULL AFTER expires_at,
  ADD COLUMN evidence_path VARCHAR(255) NULL AFTER evidence_sha256,
  ADD COLUMN captured_by INT UNSIGNED NULL AFTER evidence_path,
  ADD COLUMN revoked_at DATETIME NULL AFTER captured_by,
  ADD COLUMN revoked_by INT UNSIGNED NULL AFTER revoked_at,
  ADD COLUMN revoke_reason VARCHAR(255) NULL AFTER revoked_by,
  ADD INDEX idx_consent_ref (institution_id, consent_ref),
  ADD INDEX idx_consent_borrower (borrower_id, institution_id, expires_at);
UPDATE consents SET signed_at = DATE(granted_at) WHERE signed_at IS NULL;

ALTER TABLE inquiry_logs
  ADD INDEX idx_inquiry_inst_time (institution_id, created_at),
  ADD INDEX idx_inquiry_borrower (borrower_id, created_at);

-- Audit trail: full-content hashing (v2 rows) + incremental verification checkpoint.
-- Existing rows stay hash_version 1 (link-verified only).
ALTER TABLE audit_logs
  ADD COLUMN hash_version TINYINT UNSIGNED NOT NULL DEFAULT 1 AFTER row_hash,
  ADD COLUMN ts_ms BIGINT UNSIGNED NULL AFTER hash_version,
  ADD INDEX idx_audit_inst (institution_id, id),
  ADD INDEX idx_audit_action (action, created_at);

CREATE TABLE IF NOT EXISTS audit_checkpoints (
    id          TINYINT UNSIGNED NOT NULL PRIMARY KEY,
    last_id     BIGINT UNSIGNED NOT NULL,
    last_hash   CHAR(64) NOT NULL,
    verified_at DATETIME NOT NULL,
    broken_at   BIGINT UNSIGNED NULL,     -- sticky failure from the last full verification
    broken_reason VARCHAR(255) NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE login_attempts ADD INDEX idx_la_ip (ip_address, created_at);

-- ingestion_log.submitted_xaf held a record count, not an amount
ALTER TABLE ingestion_log CHANGE COLUMN submitted_xaf submitted INT UNSIGNED NOT NULL DEFAULT 0;

-- Disputes: furnisher response
ALTER TABLE disputes
  ADD COLUMN furnisher_response TEXT NULL AFTER status,
  ADD COLUMN furnisher_responded_at DATETIME NULL AFTER furnisher_response,
  ADD COLUMN furnisher_responded_by INT UNSIGNED NULL AFTER furnisher_responded_at;

ALTER TABLE data_corrections ADD INDEX idx_corr_entity (entity, entity_id, created_at);

CREATE TABLE IF NOT EXISTS identity_conflicts (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    institution_id      INT UNSIGNED NULL,
    source              ENUM('API','BATCH','MANUAL') NOT NULL,
    conflict_type       ENUM('MULTIPLE_MATCH','NAME_MISMATCH','DOB_MISMATCH') NOT NULL,
    matched_borrower_id INT UNSIGNED NULL,
    submitted           JSON NOT NULL,
    context             JSON NULL,
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

-- Permissions
INSERT IGNORE INTO permissions (code, description) VALUES
('borrower.reconcile', 'Review identity conflicts and merge duplicate borrowers (bureau)');

INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r JOIN permissions p
WHERE (r.code='SUPER_ADMIN')
   OR (r.code='INST_ADMIN'     AND p.code IN ('audit.view'))
   OR (r.code='COMPLIANCE'     AND p.code IN ('inquiry.view.own'))
   OR (r.code='CREDIT_OFFICER' AND p.code IN ('collateral.view.own','incident.view.own'));
