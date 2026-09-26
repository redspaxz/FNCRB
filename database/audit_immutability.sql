-- =====================================================================
-- OPTIONAL — database-level immutability for the audit trail.
-- Makes audit_logs append-only: UPDATE and DELETE are refused by the server
-- even for the application's own DB user. Install once the chain has been
-- verified (php scripts/verify_audit_chain.php).
--
-- Requires the TRIGGER privilege (and, with binary logging on MySQL,
-- log_bin_trust_function_creators=1 or SUPER). On shared hosting where this is
-- not available, rely on the hash chain + nightly verification instead.
-- The authorized repair tool (scripts/repair_audit_chain.php) needs these
-- triggers dropped first and re-created afterwards.
-- =====================================================================

DROP TRIGGER IF EXISTS audit_logs_no_update;
DROP TRIGGER IF EXISTS audit_logs_no_delete;

DELIMITER $$
CREATE TRIGGER audit_logs_no_update BEFORE UPDATE ON audit_logs
FOR EACH ROW BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'audit_logs is append-only';
END$$
CREATE TRIGGER audit_logs_no_delete BEFORE DELETE ON audit_logs
FOR EACH ROW BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'audit_logs is append-only';
END$$
DELIMITER ;
