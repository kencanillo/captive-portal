<?php

use App\Http\Controllers\Public\PayMongoWebhookController;
use App\Http\Controllers\Public\PaymentController;
use App\Http\Controllers\Public\PortalBootstrapController;
use App\Http\Controllers\Public\PlanSelectionApiController;
use Illuminate\Support\Facades\Route;

Route::get('/portal/bootstrap', PortalBootstrapController::class)->name('api.portal.bootstrap');
Route::post('/select-plan', PlanSelectionApiController::class)->name('api.select-plan');
Route::post('/create-payment', [PaymentController::class, 'create'])->name('api.create-payment');
Route::post('/paymongo/webhook', PayMongoWebhookController::class)->name('api.paymongo.webhook');
