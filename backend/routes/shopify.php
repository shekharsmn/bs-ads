<?php

use App\Shopify\Http\AgencyController;
use App\Shopify\Http\EmbeddedAuth;
use App\Shopify\Http\MerchantController;
use App\Shopify\Http\WebhookController;
use Illuminate\Support\Facades\Route;

Route::post('/shopify/webhooks/{registration}', WebhookController::class);
Route::prefix('shopify/embedded')->middleware(EmbeddedAuth::class)->group(function () {
    Route::post('session', [MerchantController::class, 'session']);
    Route::get('report', [MerchantController::class, 'report']);
    Route::post('refresh', [MerchantController::class, 'refresh'])->middleware('throttle:10,1');
    Route::post('link', [MerchantController::class, 'link'])->middleware('throttle:10,1');
    Route::post('disconnect', [MerchantController::class, 'disconnect']);
    Route::get('privacy', [MerchantController::class, 'privacy']);
    Route::get('privacy/{privacy}/export', [MerchantController::class, 'exportPrivacy']);
});
Route::middleware(['auth:sanctum', 'active', \App\Shopify\Http\FeatureEnabled::class])->group(function () {
    Route::match(['GET', 'POST'], 'shopify/registrations', [AgencyController::class, 'registrations'])->middleware(['role:admin', 'audit.mutations']);
    Route::patch('shopify/registrations/{registration}', [AgencyController::class, 'updateRegistration'])->middleware(['role:admin', 'audit.mutations']);
    Route::get('commerce/summary', [AgencyController::class, 'summary']);
    Route::prefix('clients/{client}/shopify')->group(function () {
        Route::get('/', [AgencyController::class, 'connection']);
        Route::get('report', [AgencyController::class, 'report']);
        Route::middleware(['role:admin', 'audit.mutations'])->group(function () {
            Route::post('connect', [AgencyController::class, 'connect']);
            Route::post('link', [AgencyController::class, 'link']);
            Route::post('refresh', [AgencyController::class, 'refresh'])->middleware('throttle:10,1');
            Route::delete('/', [AgencyController::class, 'disconnect']);
        });
    });
});
