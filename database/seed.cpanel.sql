-- =====================================================================
-- FNCRB seed: roles, permissions, institutions, demo users & data
-- Default passwords are for development ONLY — rotate before production.
-- =====================================================================

-- Roles
INSERT INTO roles (code, name, description) VALUES
('SUPER_ADMIN',  'System Administrator', 'Full platform administration'),
('REGULATOR',    'COBAC/BEAC Supervisor', 'Supervisory read access across all institutions'),
('INST_ADMIN',   'Institution Administrator', 'Administers own institution users and data'),
('COMPLIANCE',   'Compliance Officer', 'Regulatory reporting and provisioning for own institution'),
('CREDIT_OFFICER','Credit Officer', 'Consent-gated credit inquiries and loan reporting'),
('AUDITOR',      'Internal Auditor', 'Read-only audit trail and inquiry logs');

-- Permissions
INSERT INTO permissions (code, description) VALUES
('inquiry.perform','Run consent-gated borrower credit checks'),
('inquiry.view.own','View inquiries made by own institution'),
('inquiry.view.all','View all inquiries (regulator)'),
('loan.report','Submit/ingest loan portfolio data'),
('loan.view.own','View own institution loans'),
('loan.view.all','View all loans (regulator)'),
('borrower.manage','Create/update borrower identities'),
('collateral.manage','Register collateral (sûretés)'),
('collateral.view.own','View own institution collateral'),
('incident.report','Report payment incidents (CIP)'),
('incident.view.own','View own institution incidents'),
('incident.view.all','View all incidents (regulator)'),
('compliance.reports','Generate COBAC/BEAC reporting packages'),
('compliance.provisioning','Run classification & provisioning'),
('audit.view','View tamper-proof audit trail'),
('users.manage','Manage institution users'),
('institutions.manage','Manage institutions (super admin)');

-- Role-permission matrix
INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r JOIN permissions p
WHERE (r.code='SUPER_ADMIN')
   OR (r.code='REGULATOR'   AND p.code IN ('inquiry.view.all','loan.view.all','incident.view.all','compliance.reports','audit.view'))
   OR (r.code='INST_ADMIN'  AND p.code IN ('inquiry.view.own','loan.view.own','loan.report','borrower.manage','collateral.manage','collateral.view.own','incident.report','incident.view.own','compliance.reports','compliance.provisioning','users.manage','inquiry.perform'))
   OR (r.code='COMPLIANCE'  AND p.code IN ('loan.view.own','collateral.view.own','incident.view.own','compliance.reports','compliance.provisioning'))
   OR (r.code='CREDIT_OFFICER' AND p.code IN ('inquiry.perform','inquiry.view.own','loan.report','loan.view.own','borrower.manage','collateral.manage','incident.report'))
   OR (r.code='AUDITOR'     AND p.code IN ('audit.view','inquiry.view.own'));

-- Institutions
INSERT INTO institutions (code, name, category, legal_form, rccm_number, niu, head_office, net_equity_xaf) VALUES
('COBAC-HQ',  'COBAC Commission', 'REGULATOR', 'International body', NULL, NULL, 'Douala', 0),
('CAMCCUL',    'CAMCCUL Network', 'CAT1', 'Cooperative Union', 'RCCM/DLA/1992/118', 'M0000123456A', 'Bamenda', 4800000000),
('COOPEC-DSCH','COOPEC Douala Sud', 'CAT1', 'COOPEC', 'RCCM/DLA/2001/774', 'M0000223344B', 'Douala', 620000000),
('MICROBANK-PL','Express Micro-bank SA', 'CAT2', 'Public Limited Company', 'RCCM/YDE/2010/452', 'M0000334455C', 'Yaoundé', 1500000000),
('PROJFIN-CM', 'Projet Finance Cameroun', 'CAT3', 'Project Finance Institution', 'RCCM/DLA/2015/908', 'M0000445566D', 'Douala', 350000000),
('AFRIBANK-CM','Africa Commercial Bank CM', 'BANK', 'Commercial Bank', 'RCCM/DLA/1985/012', 'M0000556677E', 'Douala', 25000000000);

-- Users (password hashes below = "ChangeMe!2026" — DEV ONLY)
INSERT INTO users (institution_id, role_id, full_name, email, password_hash, officer_level, branch_code) VALUES
(NULL, (SELECT id FROM roles WHERE code='SUPER_ADMIN'), 'System Administrator', 'admin@fncrb.cm', '$2y$10$LqgTfGr/H7PHr0wlN9WZ5OffdoSVSDj3l9fIYVVb5pE2ia3LPcc1q', 3, NULL),
(NULL, (SELECT id FROM roles WHERE code='REGULATOR'), 'COBAC Supervisor', 'supervisor@cobac.cm', '$2y$10$LqgTfGr/H7PHr0wlN9WZ5OffdoSVSDj3l9fIYVVb5pE2ia3LPcc1q', 3, NULL),
((SELECT id FROM institutions WHERE code='MICROBANK-PL'), (SELECT id FROM roles WHERE code='INST_ADMIN'), 'Marie Ngo', 'admin@microbank.cm', '$2y$10$LqgTfGr/H7PHr0wlN9WZ5OffdoSVSDj3l9fIYVVb5pE2ia3LPcc1q', 3, 'HQ'),
((SELECT id FROM institutions WHERE code='MICROBANK-PL'), (SELECT id FROM roles WHERE code='CREDIT_OFFICER'), 'Jean Ekwalla', 'officer@microbank.cm', '$2y$10$LqgTfGr/H7PHr0wlN9WZ5OffdoSVSDj3l9fIYVVb5pE2ia3LPcc1q', 2, 'DSCH'),
((SELECT id FROM institutions WHERE code='CAMCCUL'), (SELECT id FROM roles WHERE code='CREDIT_OFFICER'), 'Agnes Fon', 'officer@camccul.cm', '$2y$10$LqgTfGr/H7PHr0wlN9WZ5OffdoSVSDj3l9fIYVVb5pE2ia3LPcc1q', 2, 'BMD'),
((SELECT id FROM institutions WHERE code='MICROBANK-PL'), (SELECT id FROM roles WHERE code='COMPLIANCE'), 'Paul Mbarga', 'compliance@microbank.cm', '$2y$10$LqgTfGr/H7PHr0wlN9WZ5OffdoSVSDj3l9fIYVVb5pE2ia3LPcc1q', 2, 'HQ'),
((SELECT id FROM institutions WHERE code='MICROBANK-PL'), (SELECT id FROM roles WHERE code='AUDITOR'), 'Sarah Ayuk', 'auditor@microbank.cm', '$2y$10$LqgTfGr/H7PHr0wlN9WZ5OffdoSVSDj3l9fIYVVb5pE2ia3LPcc1q', 2, 'HQ');

-- Borrowers
INSERT INTO borrowers (master_ref, full_name, date_of_birth, gender, type, cni_number, niu, coop_member_id, phone, region) VALUES
('FNB000000001','Etienne Tabi','1985-03-12','M','INDIVIDUAL','118545678','NIU-ET-7781','CAM-33221','+237677001122','Littoral'),
('FNB000000002','Clarisse Abena','1990-11-02','F','INDIVIDUAL','119088234',NULL,'COOP-8812','+237690223344','Centre'),
('FNB000000003','Société Bâti-Plus SARL',NULL,NULL,'CORPORATE',NULL,'NIU-BP-3345',NULL,'+237233445566','Douala'),
('FNB000000004','Hamadou Sali','1978-07-25','M','INDIVIDUAL','117812390',NULL,NULL,'+237655889900','Nord');

-- Loans
INSERT INTO loans (institution_id, borrower_id, contract_ref, loan_type, principal_xaf, outstanding_xaf, monthly_payment_xaf, interest_rate_pct, start_date, maturity_date, instalments_total, instalments_past_due, days_past_due, cobac_class, provision_xaf, status, reported_at, source) VALUES
((SELECT id FROM institutions WHERE code='CAMCCUL'),   1,'CAM-2025-0041','MICRO',    1500000,  900000,  125000, 12.5,'2025-02-10','2026-02-10',12,0,0,'HEALTHY',0,'ACTIVE','2026-08-31','BATCH'),
((SELECT id FROM institutions WHERE code='PROJFIN-CM'),1,'PFC-2025-0113','PROJECT', 5000000, 4200000, 410000, 15.0,'2025-05-01','2027-05-01',24,2,45,'WATCH',210000,'ACTIVE','2026-08-31','API'),
((SELECT id FROM institutions WHERE code='MICROBANK-PL'),1,'MBK-2024-0777','BUSINESS',8000000,6900000,520000, 13.0,'2024-09-15','2027-09-15',36,4,95,'UNCERTAIN',690000,'ACTIVE','2026-08-31','BATCH'),
((SELECT id FROM institutions WHERE code='MICROBANK-PL'),2,'MBK-2025-0201','CONSUMER',1200000, 700000, 105000, 11.0,'2025-06-20','2026-06-20',12,0,0,'HEALTHY',0,'ACTIVE','2026-08-31','BATCH'),
((SELECT id FROM institutions WHERE code='AFRIBANK-CM'),3,'ACB-2023-0512','BUSINESS',45000000,38000000,1500000,9.5,'2023-04-01','2028-04-01',60,7,180,'DOUBTFUL',15200000,'ACTIVE','2026-08-31','BATCH'),
((SELECT id FROM institutions WHERE code='CAMCCUL'),   4,'CAM-2024-0905','MICRO',   800000,  300000,   70000, 12.0,'2024-08-01','2025-08-01',12,6,210,'COMPROMISED',300000,'ACTIVE','2026-08-31','BATCH');

-- Collateral (incl. an RCCM double-pledge scenario for borrower 3)
INSERT INTO collateral (loan_id, institution_id, collateral_type, description, estimated_value_xaf, rccm_registration_no, rccm_registered_at) VALUES
(5,(SELECT id FROM institutions WHERE code='AFRIBANK-CM'),'NANTISSEMENT_EQUIPMENT','Excavator CAT 320 + concrete mixer','25000000','RCCM-DLA-SU-2023-0091','2023-03-20'),
(5,(SELECT id FROM institutions WHERE code='AFRIBANK-CM'),'NANTISSEMENT_BUSINESS','Business fund of commerce (fonds de commerce)','15000000','RCCM-DLA-SU-2023-0092','2023-03-20'),
(2,(SELECT id FROM institutions WHERE code='PROJFIN-CM'),'VEHICLE_MORTGAGE','Toyota Hilux 2019 (project vehicle)','12000000','RCCM-DLA-SU-2025-0140','2025-04-22');

INSERT INTO guarantors (borrower_id, full_name, cni_number, loan_id, guarantee_xaf, solidarity_group) VALUES
(2,'Clarisse Abena','119088234',1,500000,NULL),
(NULL,'Group Solidarité Mboppi',NULL,2,2000000,'GRPMBO-01');

-- Payment incidents (CNEF/CIP)
INSERT INTO payment_incidents (institution_id, borrower_id, incident_type, instrument_ref, amount_xaf, incident_date) VALUES
((SELECT id FROM institutions WHERE code='AFRIBANK-CM'),3,'BOUNCED_CHEQUE','CHQ-882134',3500000,'2026-01-15'),
((SELECT id FROM institutions WHERE code='MICROBANK-PL'),1,'UNAUTHORIZED_OVERDRAFT','OD-MBK-771',450000,'2026-03-02'),
((SELECT id FROM institutions WHERE code='AFRIBANK-CM'),3,'DEFAULTED_NOTE','NOTE-5521',8000000,'2026-05-10');

-- Consents (active, needed for demo inquiries)
INSERT INTO consents (borrower_id, institution_id, consent_type, consent_ref, scope, granted_at, expires_at) VALUES
(1,(SELECT id FROM institutions WHERE code='MICROBANK-PL'),'DIGITAL','CS-2026-0912-A','FULL_REPORT','2026-09-01','2026-12-01'),
(3,(SELECT id FROM institutions WHERE code='MICROBANK-PL'),'PHYSICAL','CS-2026-0877-B','CREDIT_CHECK','2026-08-20','2026-11-20');
