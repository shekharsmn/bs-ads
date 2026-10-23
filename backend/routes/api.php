<?php

use App\Http\Controllers\Api\AiLogsController;
use App\Http\Controllers\Api\AiModelController;
use App\Http\Controllers\Api\AdLibraryController;
use App\Http\Controllers\Api\AdLibraryAuditController;
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
    Route::post('/connections/{connection}/accounts/bulk', [ConnectionController::class, 'assignAccounts'])->middleware('role:admin');
    Route::get('/connections/google/redirect', [GoogleOAuthController::class, 'connectionRedirect'])->middleware('role:admin');

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

    // Admin settings
    Route::get('/settings/ai-models', [AiModelController::class, 'index'])->middleware('role:admin');
    Route::post('/settings/ai-models', [AiModelController::class, 'store'])->middleware('role:admin');
    Route::put('/settings/ai-models/{aiModel}', [AiModelController::class, 'update'])->middleware('role:admin');
    Route::delete('/settings/ai-models/{aiModel}', [AiModelController::class, 'destroy'])->middleware('role:admin');

    Route::get('/ad-library/searches', [AdLibraryController::class, 'searches']);
    Route::post('/ad-library/searches', [AdLibraryController::class, 'store']);
    Route::put('/ad-library/searches/{search}', [AdLibraryController::class, 'update']);
    Route::delete('/ad-library/searches/{search}', [AdLibraryController::class, 'destroySearch']);
    Route::post('/ad-library/searches/{search}/runs', [AdLibraryController::class, 'run']);
    Route::get('/ad-library/runs', [AdLibraryController::class, 'runs']);
    Route::get('/ad-library/runs/{run}', [AdLibraryController::class, 'showRun']);
    Route::get('/ad-library/runs/{run}/json', [AdLibraryController::class, 'runJson']);
    Route::get('/ad-library/runs/{run}/ads', [AdLibraryController::class, 'results']);
    Route::delete('/ad-library/runs/{run}', [AdLibraryController::class, 'destroyRun']);

    Route::get('/ad-library/audit-models', [AdLibraryAuditController::class, 'models']);
    Route::get('/ad-library/audits', [AdLibraryAuditController::class, 'index']);
    Route::post('/ad-library/audits', [AdLibraryAuditController::class, 'store']);
    Route::get('/ad-library/audits/{audit}', [AdLibraryAuditController::class, 'show']);
    Route::put('/ad-library/audits/{audit}', [AdLibraryAuditController::class, 'update']);
    Route::delete('/ad-library/audits/{audit}', [AdLibraryAuditController::class, 'destroy']);
    Route::post('/ad-library/audits/{audit}/context/collect', [AdLibraryAuditController::class, 'collectContext']);
    Route::put('/ad-library/audits/{audit}/context', [AdLibraryAuditController::class, 'updateContext']);
    Route::post('/ad-library/audits/{audit}/personas/generate', [AdLibraryAuditController::class, 'generatePersonas']);
    Route::post('/ad-library/audits/{audit}/personas', [AdLibraryAuditController::class, 'storePersona']);
    Route::put('/ad-library/audits/{audit}/personas/{persona}', [AdLibraryAuditController::class, 'updatePersona']);
    Route::delete('/ad-library/audits/{audit}/personas/{persona}', [AdLibraryAuditController::class, 'destroyPersona']);
    Route::put('/ad-library/audits/{audit}/personas-order', [AdLibraryAuditController::class, 'reorderPersonas']);
    Route::post('/ad-library/audits/{audit}/personas/confirm', [AdLibraryAuditController::class, 'confirmPersonas']);
    Route::post('/ad-library/audits/{audit}/analyze', [AdLibraryAuditController::class, 'analyze']);
    Route::post('/ad-library/audits/{audit}/retry-failed', [AdLibraryAuditController::class, 'retryFailed']);
    Route::get('/ad-library/audits/{audit}/creatives', [AdLibraryAuditController::class, 'creatives']);
    Route::put('/ad-library/audits/{audit}/creatives/{creative}', [AdLibraryAuditController::class, 'updateCreative']);
    Route::post('/ad-library/audits/{audit}/grid/confirm', [AdLibraryAuditController::class, 'confirmGrid']);
    Route::post('/ad-library/audits/{audit}/insights', [AdLibraryAuditController::class, 'generateInsights']);
    Route::get('/ad-library/audits/{audit}/export.csv', [AdLibraryAuditController::class, 'exportCsv']);
    Route::get('/ad-library/audits/{audit}/export.json', [AdLibraryAuditController::class, 'exportJson']);

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
