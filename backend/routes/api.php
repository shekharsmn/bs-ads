<?php

use App\Http\Controllers\Api\AccessManagementController;
use App\Http\Controllers\Api\AdLibraryAuditController;
use App\Http\Controllers\Api\AdLibraryController;
use App\Http\Controllers\Api\AiGovernanceController;
use App\Http\Controllers\Api\AiLogsController;
use App\Http\Controllers\Api\AiModelController;
use App\Http\Controllers\Api\AlertController;
use App\Http\Controllers\Api\AlertRuleController;
use App\Http\Controllers\Api\AuditEventController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ClientController;
use App\Http\Controllers\Api\ConnectionController;
use App\Http\Controllers\Api\ConversionMappingController;
use App\Http\Controllers\Api\DataQualityController;
use App\Http\Controllers\Api\GoogleAccountController;
use App\Http\Controllers\Api\GoogleOAuthController;
use App\Http\Controllers\Api\InspectorController;
use App\Http\Controllers\Api\MetaAccountController;
use App\Http\Controllers\Api\MethodologyController;
use App\Http\Controllers\Api\NotificationDestinationController;
use App\Http\Controllers\Api\OperationsReadinessController;
use App\Http\Controllers\Api\PerformanceController;
use App\Http\Controllers\Api\PerformancePulseController;
use App\Http\Controllers\Api\PublicPerformanceReportController;
use App\Http\Controllers\Api\PublicReportController;
use App\Http\Controllers\Api\ReportController;
use App\Http\Controllers\Api\ReportScheduleController;
use App\Http\Controllers\Api\ReportTemplateController;
use App\Http\Controllers\Api\WorkflowActivityController;
use App\Http\Controllers\Api\WorkspaceAuthController;
use Illuminate\Support\Facades\Route;

// Auth (guest)
Route::get('/auth/google/redirect', [WorkspaceAuthController::class, 'redirect'])->middleware('web');
Route::get('/auth/google/callback', [WorkspaceAuthController::class, 'callback'])->middleware('web');

// Google OAuth callback (browser redirect; no session required — state is in cache)
Route::get('/oauth/google/callback', [GoogleOAuthController::class, 'callback']);

// Public read-only shared report (token is unguessable)
Route::get('/public/reports/{token}', [PublicReportController::class, 'show'])->middleware('throttle:public-reports');
Route::get('/public/performance-reports/{token}', [PublicPerformanceReportController::class, 'show'])->middleware('throttle:public-reports');

// Authenticated routes
Route::middleware(['auth:sanctum', 'active', 'audit.mutations'])->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/me', [AuthController::class, 'me']);
    Route::get('/operations/readiness', OperationsReadinessController::class)->middleware('role:admin');
    Route::get('/activities', [WorkflowActivityController::class, 'index']);
    Route::get('/activities/{activity}', [WorkflowActivityController::class, 'show']);
    Route::get('/activities/{activity}/attempts', [WorkflowActivityController::class, 'attempts']);
    Route::post('/activities/{activity}/actions', [WorkflowActivityController::class, 'action'])->middleware('role:admin,marketer');

    Route::get('/clients', [ClientController::class, 'index']);
    Route::get('/clients/{client}', [ClientController::class, 'show']);
    Route::post('/clients', [ClientController::class, 'store'])->middleware('role:admin');
    Route::put('/clients/{client}', [ClientController::class, 'update'])->middleware('role:admin,marketer');
    Route::delete('/clients/{client}', [ClientController::class, 'destroy'])->middleware('role:admin');
    Route::put('/clients/{client}/assignments', [ClientController::class, 'assign'])->middleware('role:admin');
    Route::post('/clients/{client}/google-chat/test', [ClientController::class, 'testChat'])->middleware('role:admin,marketer');
    Route::get('/clients/{client}/conversion-mappings', [ConversionMappingController::class, 'index']);
    Route::post('/clients/{client}/conversion-mappings', [ConversionMappingController::class, 'store'])->middleware('role:admin,marketer');
    Route::delete('/clients/{client}/conversion-mappings/{mapping}', [ConversionMappingController::class, 'destroy'])->middleware('role:admin,marketer');
    Route::get('/users', [ClientController::class, 'users'])->middleware('role:admin');
    Route::put('/users/{user}', [ClientController::class, 'updateUser'])->middleware('role:admin');
    Route::get('/governance/users', [AccessManagementController::class, 'index'])->middleware('role:admin');
    Route::put('/governance/users/{user}', [AccessManagementController::class, 'update'])->middleware('role:admin');
    Route::get('/governance/users/{user}/history', [AccessManagementController::class, 'history'])->middleware('role:admin');
    Route::get('/user-management', [AccessManagementController::class, 'directory'])->middleware('role:admin');
    Route::post('/user-management/invitations', [AccessManagementController::class, 'invite'])->middleware('role:admin');
    Route::put('/user-management/invitations/{invitation}', [AccessManagementController::class, 'updateInvitation'])->middleware('role:admin');
    Route::post('/user-management/invitations/{invitation}/resend', [AccessManagementController::class, 'resend'])->middleware('role:admin');
    Route::post('/user-management/invitations/{invitation}/revoke', [AccessManagementController::class, 'revoke'])->middleware('role:admin');
    Route::get('/audit-events', [AuditEventController::class, 'index'])->middleware('role:admin');
    Route::get('/audit-events/export.csv', [AuditEventController::class, 'export'])->middleware('role:admin');
    Route::get('/ai-governance/summary', [AiGovernanceController::class, 'summary'])->middleware('role:admin');
    Route::get('/ai-governance/budgets', [AiGovernanceController::class, 'budgets'])->middleware('role:admin');
    Route::put('/ai-governance/budgets', [AiGovernanceController::class, 'saveBudget'])->middleware('role:admin');
    Route::get('/data-quality/issues', [DataQualityController::class, 'index']);
    Route::get('/methodologies', [MethodologyController::class, 'index']);
    Route::get('/methodologies/{key}', [MethodologyController::class, 'show']);
    Route::get('/methodologies/{key}/compare', [MethodologyController::class, 'compare']);
    Route::get('/notification-destinations', [NotificationDestinationController::class, 'index']);
    Route::post('/notification-destinations', [NotificationDestinationController::class, 'store'])->middleware('role:admin,marketer');
    Route::put('/notification-destinations/{destination}', [NotificationDestinationController::class, 'update'])->middleware('role:admin,marketer');
    Route::delete('/notification-destinations/{destination}', [NotificationDestinationController::class, 'destroy'])->middleware('role:admin,marketer');
    Route::post('/notification-destinations/{destination}/test', [NotificationDestinationController::class, 'test'])->middleware('role:admin,marketer');

    Route::get('/performance-pulses', [PerformancePulseController::class, 'index']);
    Route::get('/performance-pulses/options', [PerformancePulseController::class, 'options']);
    Route::post('/performance-pulses', [PerformancePulseController::class, 'store'])->middleware('role:admin,marketer');
    Route::put('/performance-pulses/{performancePulse}', [PerformancePulseController::class, 'update'])->middleware('role:admin,marketer');
    Route::delete('/performance-pulses/{performancePulse}', [PerformancePulseController::class, 'destroy'])->middleware('role:admin,marketer');
    Route::post('/performance-pulses/{performancePulse}/send', [PerformancePulseController::class, 'send'])->middleware('role:admin,marketer');
    Route::get('/performance-pulses/{performancePulse}/runs', [PerformancePulseController::class, 'runs']);

    Route::get('/connections', [ConnectionController::class, 'index']);
    Route::post('/connections', [ConnectionController::class, 'store'])->middleware('role:admin');
    Route::put('/connections/{connection}', [ConnectionController::class, 'update'])->middleware('role:admin');
    Route::delete('/connections/{connection}', [ConnectionController::class, 'destroy'])->middleware('role:admin');
    Route::post('/connections/{connection}/discover', [ConnectionController::class, 'discover'])->middleware('role:admin');
    Route::post('/connections/{connection}/accounts', [ConnectionController::class, 'assignAccount'])->middleware('role:admin');
    Route::post('/connections/{connection}/accounts/bulk', [ConnectionController::class, 'assignAccounts'])->middleware('role:admin');
    Route::delete('/ad-accounts/{account}', [ConnectionController::class, 'destroyAccount'])->middleware('role:admin');
    Route::get('/connections/google/redirect', [GoogleOAuthController::class, 'connectionRedirect'])->middleware('role:admin');

    Route::get('/performance/accounts', [PerformanceController::class, 'accounts']);
    Route::post('/performance/refresh', [PerformanceController::class, 'refresh']);
    Route::get('/performance/accounts/{account}/overview', [PerformanceController::class, 'overview']);
    Route::get('/performance/accounts/{account}/campaigns', [PerformanceController::class, 'campaigns']);
    Route::get('/performance/accounts/{account}/campaigns/{campaign}/ad-groups', [PerformanceController::class, 'adGroups']);
    Route::get('/performance/accounts/{account}/campaigns/{campaign}/ad-groups/{adGroup}/ads', [PerformanceController::class, 'ads']);

    Route::get('/alert-templates', [AlertRuleController::class, 'templates']);
    Route::post('/alert-templates', [AlertRuleController::class, 'storeTemplate'])->middleware('role:admin');
    Route::put('/alert-templates/{alertTemplate}', [AlertRuleController::class, 'updateTemplate'])->middleware('role:admin');
    Route::delete('/alert-templates/{alertTemplate}', [AlertRuleController::class, 'destroyTemplate'])->middleware('role:admin');
    Route::get('/alert-rules', [AlertRuleController::class, 'index']);
    Route::post('/alert-rules', [AlertRuleController::class, 'store'])->middleware('role:admin,marketer');
    Route::put('/alert-rules/{alertRule}', [AlertRuleController::class, 'update'])->middleware('role:admin,marketer');
    Route::delete('/alert-rules/{alertRule}', [AlertRuleController::class, 'destroy'])->middleware('role:admin,marketer');
    Route::delete('/workspace-alert-rules/{alertRule}', [AlertRuleController::class, 'destroyWorkspace'])->middleware('role:admin');
    Route::get('/alerts', [AlertController::class, 'index']);
    Route::get('/alerts/{alert}', [AlertController::class, 'show']);
    Route::post('/alerts/bulk-actions', [AlertController::class, 'bulkAction'])->middleware('role:admin,marketer');
    Route::post('/alerts/{alert}/{action}', [AlertController::class, 'action'])->middleware('role:admin,marketer');

    Route::get('/report-schedules', [ReportScheduleController::class, 'index']);
    Route::get('/report-templates', [ReportTemplateController::class, 'index']);
    Route::post('/report-templates', [ReportTemplateController::class, 'store'])->middleware('role:admin,marketer');
    Route::put('/report-templates/{reportTemplate}', [ReportTemplateController::class, 'update'])->middleware('role:admin,marketer');
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
    Route::get('/ai-logs', [AiLogsController::class, 'index'])->middleware('role:admin');

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
    Route::post('/ad-library/tags', [AdLibraryController::class, 'storeTag']);
    Route::put('/ad-library/searches/{search}/tags', [AdLibraryController::class, 'syncTags']);
    Route::put('/ad-library/results/{result}/favorite', [AdLibraryController::class, 'favorite']);

    Route::get('/ad-library/audit-models', [AdLibraryAuditController::class, 'models']);
    Route::get('/ad-library/audits', [AdLibraryAuditController::class, 'index']);
    Route::post('/ad-library/audits', [AdLibraryAuditController::class, 'store']);
    Route::get('/ad-library/audits/{audit}', [AdLibraryAuditController::class, 'show']);
    Route::put('/ad-library/audits/{audit}', [AdLibraryAuditController::class, 'update']);
    Route::delete('/ad-library/audits/{audit}', [AdLibraryAuditController::class, 'destroy']);
    Route::post('/ad-library/audits/{audit}/context/collect', [AdLibraryAuditController::class, 'collectContext']);
    Route::put('/ad-library/audits/{audit}/context', [AdLibraryAuditController::class, 'updateContext']);
    Route::post('/ad-library/audits/{audit}/context/confirm', [AdLibraryAuditController::class, 'confirmContext']);
    Route::post('/ad-library/audits/{audit}/personas/generate', [AdLibraryAuditController::class, 'generatePersonas']);
    Route::post('/ad-library/audits/{audit}/personas', [AdLibraryAuditController::class, 'storePersona']);
    Route::put('/ad-library/audits/{audit}/personas/{persona}', [AdLibraryAuditController::class, 'updatePersona']);
    Route::delete('/ad-library/audits/{audit}/personas/{persona}', [AdLibraryAuditController::class, 'destroyPersona']);
    Route::put('/ad-library/audits/{audit}/personas-order', [AdLibraryAuditController::class, 'reorderPersonas']);
    Route::post('/ad-library/audits/{audit}/personas/{persona}/merge', [AdLibraryAuditController::class, 'mergePersona']);
    Route::post('/ad-library/audits/{audit}/personas/confirm', [AdLibraryAuditController::class, 'confirmPersonas']);
    Route::post('/ad-library/audits/{audit}/personas/consolidate', [AdLibraryAuditController::class, 'consolidatePersonas']);
    Route::post('/ad-library/audits/{audit}/analyze', [AdLibraryAuditController::class, 'analyze']);
    Route::post('/ad-library/audits/{audit}/pause', [AdLibraryAuditController::class, 'pause']);
    Route::post('/ad-library/audits/{audit}/resume', [AdLibraryAuditController::class, 'resume']);
    Route::post('/ad-library/audits/{audit}/retry-failed', [AdLibraryAuditController::class, 'retryFailed']);
    Route::post('/ad-library/audits/{audit}/creatives/{creative}/skip', [AdLibraryAuditController::class, 'skipCreative']);
    Route::get('/ad-library/audits/{audit}/creatives', [AdLibraryAuditController::class, 'creatives']);
    Route::post('/ad-library/audits/{audit}/creatives/review-all', [AdLibraryAuditController::class, 'markAllCreativesReviewed']);
    Route::put('/ad-library/audits/{audit}/creatives/{creative}', [AdLibraryAuditController::class, 'updateCreative']);
    Route::post('/ad-library/audits/{audit}/grid/confirm', [AdLibraryAuditController::class, 'confirmGrid']);
    Route::post('/ad-library/audits/{audit}/insights', [AdLibraryAuditController::class, 'generateInsights']);
    Route::get('/ad-library/audits/{audit}/export.csv', [AdLibraryAuditController::class, 'exportCsv']);
    Route::get('/ad-library/audits/{audit}/export.xlsx', [AdLibraryAuditController::class, 'exportXlsx']);
    Route::get('/ad-library/audits/{audit}/export.sheets', [AdLibraryAuditController::class, 'exportSheets']);
    Route::get('/ad-library/audits/{audit}/report.xlsx', [AdLibraryAuditController::class, 'exportReportXlsx']);
    Route::get('/ad-library/audits/{audit}/report.sheets', [AdLibraryAuditController::class, 'exportReportSheets']);
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
