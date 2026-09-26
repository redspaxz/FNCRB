<?php
declare(strict_types=1);

use App\Controllers\AccountController;
use App\Controllers\ApiController;
use App\Controllers\AuditController;
use App\Controllers\AnalyticsController;
use App\Controllers\AuthController;
use App\Controllers\BorrowerController;
use App\Controllers\CollateralController;
use App\Controllers\ComplianceController;
use App\Controllers\ConsentController;
use App\Controllers\DashboardController;
use App\Controllers\DisputeController;
use App\Controllers\IncidentController;
use App\Controllers\InquiryLogController;
use App\Controllers\InstitutionController;
use App\Controllers\LoanController;
use App\Controllers\PageController;
use App\Controllers\ReconciliationController;
use App\Controllers\ReportController;
use App\Controllers\UserController;

/** @var \App\Core\Router $router */

$router->get('/',                    [PageController::class, 'home']);
$router->get('/terms',               [PageController::class, 'terms']);
$router->get('/login',               [AuthController::class, 'showLogin']);
$router->post('/login',              [AuthController::class, 'login']);
$router->get('/logout',              [AuthController::class, 'confirmLogout']);
$router->post('/logout',             [AuthController::class, 'logout']);

$router->get('/dashboard',           [DashboardController::class, 'index']);
$router->get('/dashboard/analytics', [DashboardController::class, 'analytics']);

$router->get('/borrowers',           [BorrowerController::class, 'index']);
$router->get('/borrowers/create',    [BorrowerController::class, 'create']);
$router->post('/borrowers',          [BorrowerController::class, 'store']);
$router->get('/borrowers/report',    [BorrowerController::class, 'consumerReport']);
$router->get('/inquiry',             [BorrowerController::class, 'inquiry']);
$router->post('/inquiry',            [BorrowerController::class, 'inquiry']);
$router->get('/inquiries',           [InquiryLogController::class, 'index']);
$router->get('/consents',            [ConsentController::class, 'index']);
$router->post('/consents/revoke',    [ConsentController::class, 'revoke']);
$router->get('/consents/evidence',   [ConsentController::class, 'evidence']);

$router->get('/loans',               [LoanController::class, 'index']);
$router->get('/loans/create',        [LoanController::class, 'create']);
$router->post('/loans',              [LoanController::class, 'store']);

$router->get('/collateral',          [CollateralController::class, 'index']);
$router->post('/collateral',         [CollateralController::class, 'store']);
$router->post('/collateral/status',  [CollateralController::class, 'status']);

$router->get('/incidents',           [IncidentController::class, 'index']);
$router->post('/incidents',          [IncidentController::class, 'store']);
$router->post('/incidents/resolve',  [IncidentController::class, 'resolve']);

$router->get('/compliance',          [ComplianceController::class, 'index']);
$router->get('/compliance/supervisory-package', [ComplianceController::class, 'supervisoryPackage']);
$router->get('/compliance/concentration',       [ComplianceController::class, 'concentration']);
$router->post('/compliance/reclassify',         [ComplianceController::class, 'reclassify']);

$router->get('/audit',               [AuditController::class, 'index']);
$router->post('/audit/verify',       [AuditController::class, 'verify']);

// Account & user administration
$router->get('/account',             [AccountController::class, 'index']);
$router->post('/account/password',   [AccountController::class, 'changePassword']);
$router->post('/account/2fa/start',  [AccountController::class, 'start2fa']);
$router->post('/account/2fa/confirm',[AccountController::class, 'confirm2fa']);
$router->post('/account/2fa/disable',[AccountController::class, 'disable2fa']);
$router->get('/users',               [UserController::class, 'index']);
$router->get('/users/create',        [UserController::class, 'create']);
$router->post('/users',              [UserController::class, 'store']);
$router->post('/users/toggle',       [UserController::class, 'toggle']);
$router->post('/users/reset-password', [UserController::class, 'resetPassword']);
$router->post('/users/reset-2fa',    [UserController::class, 'reset2fa']);

// Bureau administration
$router->get('/institutions',        [InstitutionController::class, 'index']);
$router->get('/institutions/create', [InstitutionController::class, 'create']);
$router->get('/institutions/edit',   [InstitutionController::class, 'edit']);
$router->post('/institutions',       [InstitutionController::class, 'store']);
$router->post('/institutions/update',[InstitutionController::class, 'update']);
$router->post('/institutions/rotate-key', [InstitutionController::class, 'rotateKey']);
$router->get('/reconciliation',      [ReconciliationController::class, 'index']);
$router->post('/reconciliation/resolve', [ReconciliationController::class, 'resolve']);
$router->post('/reconciliation/merge',   [ReconciliationController::class, 'merge']);

// Machine-to-machine API
$router->post('/api/v1/loans',       [ApiController::class, 'ingestLoans']);
$router->post('/api/v1/inquiry',     [ApiController::class, 'inquiry']);
$router->post('/api/v1/incidents',   [ApiController::class, 'reportIncident']);
$router->get('/api/v1/supervisory-package', [ApiController::class, 'supervisoryPackage']);

// Analytics & consumer disputes
$router->get('/analytics',              [AnalyticsController::class, 'index']);
$router->get('/analytics.json',         [AnalyticsController::class, 'json']);
$router->get('/disputes',               [DisputeController::class, 'index']);
$router->get('/disputes/create',        [DisputeController::class, 'create']);
$router->post('/disputes',              [DisputeController::class, 'store']);
$router->post('/disputes/transition',   [DisputeController::class, 'transition']);
$router->post('/disputes/respond',      [DisputeController::class, 'respond']);

// Report downloads (session users)
$router->get('/reports/loans.csv',   [ReportController::class, 'loansCsv']);
$router->get('/reports/loans.xlsx',  [ReportController::class, 'loansXlsx']);
$router->get('/reports/supervisory.xlsx', [ReportController::class, 'supervisoryXlsx']);
$router->get('/reports/incidents.csv',    [ReportController::class, 'incidentsCsv']);
