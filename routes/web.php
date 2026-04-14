<?php

use App\Http\Controllers\Admin\AccessPointController;
use App\Http\Controllers\Admin\ControllerSettingsController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\PaymentController as AdminPaymentController;
use App\Http\Controllers\Admin\PlanController;
use App\Http\Controllers\Admin\SessionController;
use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\ConfirmablePasswordController;
use App\Http\Controllers\Auth\EmailVerificationNotificationController;
use App\Http\Controllers\Auth\EmailVerificationPromptController;
use App\Http\Controllers\Auth\NewPasswordController;
use App\Http\Controllers\Auth\PasswordController;
use App\Http\Controllers\Auth\PasswordResetLinkController;
use App\Http\Controllers\Auth\VerifyEmailController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\Public\CaptivePortalController;
use App\Http\Controllers\Public\PaymentController;
use Illuminate\Support\Facades\Route;

// Client Captive Portal Routes (Public Access)
Route::get('/', CaptivePortalController::class)->name('portal.index');
Route::redirect('/login', '/admin/login')->name('login');
Route::get('/admin', function () {
    $user = request()->user();

    if (! $user) {
        return redirect()->route('admin.login');
    }

    return $user->can('access-admin')
        ? redirect()->route('admin.dashboard')
        : redirect()->route('portal.index');
})->name('admin.index');
Route::get('/payment/success', [PaymentController::class, 'success'])->name('payment.success');
Route::get('/payment/failed', [PaymentController::class, 'failed'])->name('payment.failed');

// Admin Authentication Routes (Separate from Client Portal)
Route::prefix('admin')->middleware('guest')->name('admin.')->group(function () {
    Route::get('/login', [AuthenticatedSessionController::class, 'create'])->name('login');
    Route::post('/login', [AuthenticatedSessionController::class, 'store']);
    Route::get('/forgot-password', [PasswordResetLinkController::class, 'create'])->name('password.request');
    Route::post('/forgot-password', [PasswordResetLinkController::class, 'store'])->name('password.email');
    Route::get('/reset-password/{token}', [NewPasswordController::class, 'create'])->name('password.reset');
    Route::post('/reset-password', [NewPasswordController::class, 'store'])->name('password.store');
});

Route::prefix('admin')->middleware('auth')->name('admin.')->group(function () {
    Route::get('/verify-email', EmailVerificationPromptController::class)->name('verification.notice');
    Route::get('/verify-email/{id}/{hash}', VerifyEmailController::class)->middleware(['signed', 'throttle:6,1'])->name('verification.verify');
    Route::post('/email/verification-notification', [EmailVerificationNotificationController::class, 'store'])->middleware('throttle:6,1')->name('verification.send');
    Route::get('/confirm-password', [ConfirmablePasswordController::class, 'show'])->name('password.confirm');
    Route::post('/confirm-password', [ConfirmablePasswordController::class, 'store']);
    Route::put('/password', [PasswordController::class, 'update'])->name('password.update');
    Route::post('/logout', [AuthenticatedSessionController::class, 'destroy'])->name('logout');
});

// Admin Dashboard (Protected)
Route::get('/dashboard', function () {
    return redirect()->route('admin.dashboard');
})->middleware(['auth', 'can:access-admin'])->name('dashboard');

// Admin Profile Management
Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

// Admin Panel Routes (Protected)
Route::middleware(['auth', 'can:access-admin'])->prefix('admin')->name('admin.')->group(function (): void {
    Route::get('/dashboard', DashboardController::class)->name('dashboard');
    Route::get('/controller', [ControllerSettingsController::class, 'edit'])->name('controller.edit');
    Route::put('/controller', [ControllerSettingsController::class, 'update'])->name('controller.update');
    Route::post('/controller/test-connection', [ControllerSettingsController::class, 'testConnection'])->name('controller.test');
    Route::get('/access-points', [AccessPointController::class, 'index'])->name('access-points.index');
    Route::post('/access-points', [AccessPointController::class, 'store'])->name('access-points.store');
    Route::post('/access-points/sync', [AccessPointController::class, 'sync'])->name('access-points.sync');
    Route::put('/access-points/{accessPoint}', [AccessPointController::class, 'update'])->name('access-points.update');
    Route::delete('/access-points/{accessPoint}', [AccessPointController::class, 'destroy'])->name('access-points.destroy');
    Route::get('/plans', [PlanController::class, 'index'])->name('plans.index');
    Route::post('/plans', [PlanController::class, 'store'])->name('plans.store');
    Route::put('/plans/{plan}', [PlanController::class, 'update'])->name('plans.update');
    Route::delete('/plans/{plan}', [PlanController::class, 'destroy'])->name('plans.destroy');
    Route::get('/sessions', [SessionController::class, 'index'])->name('sessions.index');
    Route::get('/payments', [AdminPaymentController::class, 'index'])->name('payments.index');
});
