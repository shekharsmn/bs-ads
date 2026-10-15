<?php

use App\Http\Controllers\Api\AiLogsController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\AlertController;
use App\Http\Controllers\Api\AlertRuleController;
use App\Http\Controllers\Api\ClientController;
use App\Http\Controllers\Api\ConnectionController;
use App\Http\Controllers\Api\ConversionMappingController;
use App\Http\Controllers\Api\GoogleAccountController;
use App\Http\Controllers\Api\GoogleOAuthController;
use App\Http\Controllers\Api\InspectorController;
use App\Http\Controllers\Api\MetaAccountController;
use App\Http\Controllers\Api\MetricsController;
use App\Http\Controllers\Api\PublicPerformanceReportController;
use App\Http\Controllers\Api\PublicReportController;
use App\Http\Controllers\Api\ReportController;
use App\Http\Controllers\Api\ReportScheduleController;
use App\Http\Controllers\Api\WorkspaceAuthController;
use Illuminate\Support\Facades\Route;

// Auth (guest)
Route::get('/auth/google/redirect', [WorkspaceAuthController::class, 'redirect'])->middleware('web');
Route::get('/auth/google/callback', [WorkspaceAuthController::class, 'callback'])->middleware('web');

// Google OAuth callback (browser redirect; no session required — state is in cache)
Route::get('/oauth/google/callback', [GoogleOAuthController::class, 'callback']);

// Public read-only shared report (token is unguessable)
Route::get('/public/reports/{token}', [PublicReportController::class, 'show']);
Route::get('/public/performance-reports/{token}', [PublicPerformanceReportController::class, 'show']);

// Authenticated routes
Route::middleware('auth:sanctum')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/me', [AuthController::class, 'me']);

    Route::get('/clients', [ClientController::class, 'index']);
    Route::get('/clients/{client}', [ClientController::class, 'show']);
    Route::post('/clients', [ClientController::class, 'store'])->middleware('role:admin');
    Route::put('/clients/{client}', [ClientController::class, 'update'])->middleware('role:admin,marketer');
    Route::put('/clients/{client}/assignments', [ClientController::class, 'assign'])->middleware('role:admin');
    Route::post('/clients/{client}/google-chat/test', [ClientController::class, 'testChat'])->middleware('role:admin,marketer');
    Route::get('/clients/{client}/conversion-mappings', [ConversionMappingController::class, 'index']);
    Route::post('/clients/{client}/conversion-mappings', [ConversionMappingController::class, 'store'])->middleware('role:admin,marketer');
    Route::delete('/clients/{client}/conversion-mappings/{mapping}', [ConversionMappingController::class, 'destroy'])->middleware('role:admin,marketer');
    Route::get('/users', [ClientController::class, 'users'])->middleware('role:admin');
    Route::put('/users/{user}', [ClientController::class, 'updateUser'])->middleware('role:admin');

    Route::get('/connections', [ConnectionController::class, 'index']);
    Route::post('/connections', [ConnectionController::class, 'store'])->middleware('role:admin');
    Route::put('/connections/{connection}', [ConnectionController::class, 'update'])->middleware('role:admin');
    Route::post('/connections/{connection}/discover', [ConnectionController::class, 'discover'])->middleware('role:admin');
    Route::post('/connections/{connection}/accounts', [ConnectionController::class, 'assignAccount'])->middleware('role:admin');

    Route::get('/metrics/summary', [MetricsController::class, 'summary']);
    Route::get('/metrics/trends', [MetricsController::class, 'trends']);
    Route::get('/metrics/campaigns', [MetricsController::class, 'campaigns']);

    Route::get('/alert-templates', [AlertRuleController::class, 'templates']);
    Route::post('/alert-templates', [AlertRuleController::class, 'storeTemplate'])->middleware('role:admin');
    Route::get('/alert-rules', [AlertRuleController::class, 'index']);
    Route::post('/alert-rules', [AlertRuleController::class, 'store'])->middleware('role:admin,marketer');
    Route::put('/alert-rules/{alertRule}', [AlertRuleController::class, 'update'])->middleware('role:admin,marketer');
    Route::delete('/alert-rules/{alertRule}', [AlertRuleController::class, 'destroy'])->middleware('role:admin,marketer');
    Route::get('/alerts', [AlertController::class, 'index']);
    Route::get('/alerts/{alert}', [AlertController::class, 'show']);
    Route::post('/alerts/{alert}/{action}', [AlertController::class, 'action'])->middleware('role:admin,marketer');

    Route::get('/report-schedules', [ReportScheduleController::class, 'index']);
    Route::post('/report-schedules', [ReportScheduleController::class, 'store'])->middleware('role:admin,marketer');
    Route::put('/report-schedules/{reportSchedule}', [ReportScheduleController::class, 'update'])->middleware('role:admin,marketer');
    Route::post('/clients/{client}/report-runs', [ReportScheduleController::class, 'generate'])->middleware('role:admin,marketer');

    // Meta accounts
    Route::get('/accounts', [MetaAccountController::class, 'index']);
    Route::post('/accounts', [MetaAccountController::class, 'store']);
    Route::put('/accounts/{metaAccount}', [MetaAccountController::class, 'update']);
    Route::delete('/accounts/{metaAccount}', [MetaAccountController::class, 'destroy']);

    // Google Ads OAuth + accounts
    Route::get('/oauth/google/redirect', [GoogleOAuthController::class, 'redirect']);
    Route::get('/google-accounts/oauth-pending', [GoogleAccountController::class, 'oauthPending']);
    Route::get('/google-accounts', [GoogleAccountController::class, 'index']);
    Route::post('/google-accounts', [GoogleAccountController::class, 'store']);
    Route::put('/google-accounts/{googleAccount}', [GoogleAccountController::class, 'update']);
    Route::delete('/google-accounts/{googleAccount}', [GoogleAccountController::class, 'destroy']);

    // Inspector
    Route::post('/inspect', [InspectorController::class, 'fetch']);

    // AI logs
    Route::get('/ai-logs', [AiLogsController::class, 'index']);

    // Reports
    Route::get('/reports', [ReportController::class, 'index']);
    Route::post('/reports', [ReportController::class, 'store']);
    Route::get('/reports/{report}', [ReportController::class, 'show']);
    Route::get('/reports/{report}/compare', [ReportController::class, 'compare']);
    Route::get('/reports/{report}/export.csv', [ReportController::class, 'exportCsv']);
    Route::post('/reports/{report}/share', [ReportController::class, 'enableShare']);
    Route::delete('/reports/{report}/share', [ReportController::class, 'disableShare']);
    Route::get('/reports/{report}/status', [ReportController::class, 'status']);
    Route::post('/reports/{report}/cancel', [ReportController::class, 'cancel']);
    Route::delete('/reports/{report}', [ReportController::class, 'destroy']);
});
