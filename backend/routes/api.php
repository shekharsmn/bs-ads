<?php

use App\Http\Controllers\Api\AiLogsController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\GoogleAccountController;
use App\Http\Controllers\Api\GoogleOAuthController;
use App\Http\Controllers\Api\InspectorController;
use App\Http\Controllers\Api\MetaAccountController;
use App\Http\Controllers\Api\PublicReportController;
use App\Http\Controllers\Api\ReportController;
use Illuminate\Support\Facades\Route;

// Auth (guest)
Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);

// Google OAuth callback (browser redirect; no session required — state is in cache)
Route::get('/oauth/google/callback', [GoogleOAuthController::class, 'callback']);

// Public read-only shared report (token is unguessable)
Route::get('/public/reports/{token}', [PublicReportController::class, 'show']);

// Authenticated routes
Route::middleware('auth:sanctum')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/me', [AuthController::class, 'me']);

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
