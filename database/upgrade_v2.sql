-- =====================================================================
-- FNCRB upgrade v2 — API channel hardening & report exports
-- Apply to an existing installation:  mysql -u root fncrb < upgrade_v2.sql
-- (schema.sql already includes these for fresh installs.)
-- =====================================================================
USE fncrb;

-- Per-institution IP allow-list for machine API (comma/space separated, NULL = open)
ALTER TABLE institutions ADD COLUMN ip_allowlist TEXT NULL;

-- Nonce replay protection + implicit request-rate metering (1 signed request = 1 nonce)
CREATE TABLE IF NOT EXISTS api_nonces (
    nonce          CHAR(64)     NOT NULL PRIMARY KEY,
    institution_id INT UNSIGNED NOT NULL,
    ip_address     VARCHAR(45)  NULL,
    created_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_nonce_inst_time (institution_id, created_at),
    FOREIGN KEY (institution_id) REFERENCES institutions(id)
) ENGINE=InnoDB;
