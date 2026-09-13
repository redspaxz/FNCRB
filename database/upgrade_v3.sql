-- =====================================================================
-- FNCRB upgrade v3 — data quality, bureau ops, disputes
-- Apply:  mysql -u root fncrb < database/upgrade_v3.sql
-- =====================================================================
USE fncrb;

-- Every ingestion batch/API submission (accuracy + timeliness metrics source)
CREATE TABLE IF NOT EXISTS ingestion_log (
    id             BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    institution_id INT UNSIGNED NOT NULL,
    source         ENUM('API','BATCH','MANUAL') NOT NULL,
    submitted_xaf  INT UNSIGNED NOT NULL DEFAULT 0,
    accepted       INT UNSIGNED NOT NULL DEFAULT 0,
    rejected       INT UNSIGNED NOT NULL DEFAULT 0,
    created_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (institution_id) REFERENCES institutions(id),
    INDEX idx_ing_inst_time (institution_id, created_at)
) ENGINE=InnoDB;

-- Consumer rights: credit disputes & correction workflow (privacy law / Law 2010/012)
CREATE TABLE IF NOT EXISTS disputes (
    id                 INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    reference          CHAR(14) NOT NULL UNIQUE,          -- DSP-YYYY-NNNN
    borrower_id        INT UNSIGNED NOT NULL,
    filed_by_inst_id   INT UNSIGNED NOT NULL,             -- institution filing on behalf of the consumer
    against_inst_id    INT UNSIGNED NULL,                 -- institution whose data is disputed (NULL = registry-wide)
    dispute_type       ENUM('INACCURATE_BALANCE','WRONG_CLASSIFICATION','NOT_MY_LOAN','DUPLICATE_IDENTITY','STALE_DATA','OTHER') NOT NULL,
    loan_id            INT UNSIGNED NULL,
    details            TEXT NOT NULL,
    status             ENUM('OPEN','UNDER_REVIEW','CORRECTED','REJECTED','WITHDRAWN') NOT NULL DEFAULT 'OPEN',
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
) ENGINE=InnoDB;

CREATE INDEX idx_disputes_status ON disputes (status, sla_due_at);

-- Data corrections applied as a result of disputes (evidence trail)
CREATE TABLE IF NOT EXISTS data_corrections (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    dispute_id  INT UNSIGNED NOT NULL,
    entity      VARCHAR(40) NOT NULL,      -- loans / borrowers / payment_incidents
    entity_id   INT UNSIGNED NOT NULL,
    field       VARCHAR(60) NOT NULL,
    old_value   TEXT NULL,
    new_value   TEXT NULL,
    corrected_by INT UNSIGNED NOT NULL,
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (dispute_id) REFERENCES disputes(id),
    FOREIGN KEY (corrected_by) REFERENCES users(id)
) ENGINE=InnoDB;

-- permissions for the new workflows
INSERT INTO permissions (code, description) VALUES
('disputes.file',   'File consumer credit disputes on behalf of borrowers'),
('disputes.work',   'Review, correct or reject disputes (bureau staff/regulator)'),
('analytics.view',  'View data quality, bureau ops and system performance analytics');

INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r JOIN permissions p
WHERE (r.code='SUPER_ADMIN')
   OR (r.code='REGULATOR'   AND p.code IN ('disputes.work','analytics.view'))
   OR (r.code='INST_ADMIN'  AND p.code IN ('disputes.file','analytics.view'))
   OR (r.code='COMPLIANCE'  AND p.code IN ('disputes.file','analytics.view'))
   OR (r.code='CREDIT_OFFICER' AND p.code IN ('disputes.file'));
