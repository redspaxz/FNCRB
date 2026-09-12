<?php
declare(strict_types=1);

use App\Controllers\AccountController;
use App\Controllers\ApiController;
use App\Controllers\AuditController;
use App\Controllers\AuthController;
use App\Controllers\BorrowerController;
use App\Controllers\CollateralController;
use App\Controllers\ComplianceController;
use App\Controllers\DashboardController;
use App\Controllers\IncidentController;
use App\Controllers\LoanController;
use App\Controllers\PageController;
use App\Controllers\UserController;

/** @var Router $router */

$router->get('/',                    [PageController::class, 'home']);
$router->get('/terms',               [PageController::class, 'terms']);
$router->get('/login',               [AuthController::class, 'showLogin']);
$router->post('/login',              [AuthController::class, 'login']);
$router->get('/logout',              [AuthController::class, 'logout']);

$router->get('/dashboard',           [DashboardController::class, 'index']);

$router->get('/borrowers',           [BorrowerController::class, 'index']);
$router->get('/borrowers/create',    [BorrowerController::class, 'create']);
$router->post('/borrowers',          [BorrowerController::class, 'store']);
$router->get('/inquiry',             [BorrowerController::class, 'inquiry']);
$router->post('/inquiry',            [BorrowerController::class, 'inquiry']);

$router->get('/loans',               [LoanController::class, 'index']);
$router->get('/loans/create',        [LoanController::class, 'create']);
$router->post('/loans',              [LoanController::class, 'store']);

$router->get('/collateral',          [CollateralController::class, 'index']);
$router->post('/collateral',         [CollateralController::class, 'store']);

$router->get('/incidents',           [IncidentController::class, 'index']);
$router->post('/incidents',          [IncidentController::class, 'store']);

$router->get('/compliance',          [ComplianceController::class, 'index']);
$router->get('/compliance/supervisory-package', [ComplianceController::class, 'supervisoryPackage']);
$router->get('/compliance/concentration',       [ComplianceController::class, 'concentration']);
$router->post('/compliance/reclassify',         [ComplianceController::class, 'reclassify']);

$router->get('/audit',               [AuditController::class, 'index']);

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

// Machine-to-machine API
$router->post('/api/v1/loans',       [ApiController::class, 'ingestLoans']);
$router->post('/api/v1/inquiry',     [ApiController::class, 'inquiry']);
$router->get('/api/v1/supervisory-package', [ApiController::class, 'supervisoryPackage']);
