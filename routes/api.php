<?php

use App\Http\Controllers\Public\PayMongoWebhookController;
use App\Http\Controllers\Public\PaymentController;
use App\Http\Controllers\Public\PortalBootstrapController;
use App\Http\Controllers\Public\PortalPlansController;
use App\Http\Controllers\Public\PlanSelectionApiController;
use Illuminate\Support\Facades\Route;

Route::middleware('throttle:portal-bootstrap')->get('/portal/bootstrap', PortalBootstrapController::class)->name('api.portal.bootstrap');
Route::middleware('throttle:portal-plans')->get('/portal/plans', PortalPlansController::class)->name('api.portal.plans');
Route::middleware('throttle:portal-select-plan')->post('/select-plan', PlanSelectionApiController::class)->name('api.select-plan');
Route::middleware('throttle:portal-create-payment')->post('/create-payment', [PaymentController::class, 'create'])->name('api.create-payment');
Route::post('/paymongo/webhook', PayMongoWebhookController::class)->name('api.paymongo.webhook');
