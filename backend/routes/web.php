<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/shopify/app/{registration}', [\App\Shopify\Http\MerchantController::class, 'shell']);
