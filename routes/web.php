<?php

use App\Http\Controllers\Admin\AccessPointController;
use App\Http\Controllers\Admin\AccessPointClaimController as AdminAccessPointClaimController;
use App\Http\Controllers\Admin\ControllerSettingsController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\DeviceTransferRequestController;
use App\Http\Controllers\Admin\OperatorController as AdminOperatorController;
use App\Http\Controllers\Admin\PaymentController as AdminPaymentController;
use App\Http\Controllers\Admin\PayoutRequestController as AdminPayoutRequestController;
use App\Http\Controllers\Admin\PlanController;
use App\Http\Controllers\Admin\SessionController;
use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\ConfirmablePasswordController;
use App\Http\Controllers\Auth\EmailVerificationNotificationController;
use App\Http\Controllers\Auth\EmailVerificationPromptController;
use App\Http\Controllers\Auth\NewPasswordController;
use App\Http\Controllers\Auth\OperatorRegistrationController;
use App\Http\Controllers\Auth\PasswordController;
use App\Http\Controllers\Auth\PasswordResetLinkController;
use App\Http\Controllers\Auth\VerifyEmailController;
use App\Http\Controllers\Operator\DashboardController as OperatorDashboardController;
use App\Http\Controllers\Operator\DeviceController as DeviceController;
use App\Http\Controllers\Operator\AccessPointClaimController as OperatorAccessPointClaimController;
use App\Http\Controllers\Operator\PayoutController as OperatorPayoutController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\Public\CaptivePortalController;
use App\Http\Controllers\Public\PaymentController;
use App\Http\Controllers\Public\PaymentRecheckController;
use App\Http\Controllers\Public\PaymentStatusController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', CaptivePortalController::class)->name('portal.index');
Route::redirect('/login', '/admin/login')->name('login');

Route::get('/admin', function () {
    $user = request()->user()?->loadMissing('operator');

    if (! $user) {
        return redirect()->route('admin.login');
    }

    if ($user->can('access-admin')) {
        return redirect()->route('admin.dashboard');
    }

    if ($user->can('access-operator-panel')) {
        return redirect()->route('operator.dashboard');
    }

    if ($user->operator) {
        return redirect()->route('operator.pending');
    }

    return redirect()->route('portal.index');
})->name('admin.index');

Route::get('/operator/register', [OperatorRegistrationController::class, 'create'])->middleware('guest')->name('operator.register');
Route::post('/operator/register', [OperatorRegistrationController::class, 'store'])->middleware('guest')->name('operator.register.store');

Route::get('/payments/{paymentToken}', [PaymentController::class, 'show'])->name('payments.show');
Route::get('/payments/{paymentToken}/status', PaymentStatusController::class)->name('payments.status.show');
Route::post('/payments/{paymentToken}/recheck', PaymentRecheckController::class)->name('payments.recheck.store');
Route::get('/payment/success', [PaymentController::class, 'success'])->name('payment.success');
Route::get('/payment/failed', [PaymentController::class, 'failed'])->name('payment.failed');

Route::prefix('admin')->middleware('guest')->group(function () {
    Route::get('/login', [AuthenticatedSessionController::class, 'create'])->name('admin.login');
    Route::post('/login', [AuthenticatedSessionController::class, 'store']);
    Route::get('/forgot-password', [PasswordResetLinkController::class, 'create'])->name('password.request');
    Route::post('/forgot-password', [PasswordResetLinkController::class, 'store'])->name('password.email');
    Route::get('/reset-password/{token}', [NewPasswordController::class, 'create'])->name('password.reset');
    Route::post('/reset-password', [NewPasswordController::class, 'store'])->name('password.store');
});

Route::prefix('admin')->middleware('auth')->group(function () {
    Route::get('/verify-email', EmailVerificationPromptController::class)->name('verification.notice');
    Route::get('/verify-email/{id}/{hash}', VerifyEmailController::class)->middleware(['signed', 'throttle:6,1'])->name('verification.verify');
    Route::post('/email/verification-notification', [EmailVerificationNotificationController::class, 'store'])->middleware('throttle:6,1')->name('verification.send');
    Route::get('/confirm-password', [ConfirmablePasswordController::class, 'show'])->name('password.confirm');
    Route::post('/confirm-password', [ConfirmablePasswordController::class, 'store']);
    Route::put('/password', [PasswordController::class, 'update'])->name('password.update');
    Route::post('/logout', [AuthenticatedSessionController::class, 'destroy'])->name('admin.logout');
});

Route::post('/logout', [AuthenticatedSessionController::class, 'destroy'])
    ->middleware('auth')
    ->name('logout');

Route::get('/dashboard', function () {
    $user = request()->user()?->loadMissing('operator');

    if (! $user) {
        return redirect()->route('admin.login');
    }

    if ($user->can('access-admin')) {
        return redirect()->route('admin.dashboard');
    }

    if ($user->can('access-operator-panel')) {
        return redirect()->route('operator.dashboard');
    }

    if ($user->operator) {
        return redirect()->route('operator.pending');
    }

    return redirect()->route('portal.index');
})->middleware('auth')->name('dashboard');

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
    Route::get('/settings', SettingsController::class)->name('settings.index');

    Route::get('/operator/pending', function () {
        $operator = request()->user()?->loadMissing('operator')->operator;

        if (! $operator) {
            abort(403);
        }

        if (request()->user()->can('access-operator-panel')) {
            return redirect()->route('operator.dashboard');
        }

        return Inertia::render('Operator/PendingApproval', [
            'operator' => [
                'business_name' => $operator->business_name,
                'status' => $operator->status,
                'approval_notes' => $operator->approval_notes,
            ],
        ]);
    })->name('operator.pending');
});

Route::middleware(['auth', 'can:access-admin'])->prefix('admin')->name('admin.')->group(function (): void {
    Route::get('/dashboard', DashboardController::class)->name('dashboard');
    Route::post('/dashboard/verify-operations', [DashboardController::class, 'verify'])->name('dashboard.verify-operations');
    Route::get('/controller', [ControllerSettingsController::class, 'edit'])->name('controller.edit');
    Route::put('/controller', [ControllerSettingsController::class, 'update'])->name('controller.update');
    Route::post('/controller/test-connection', [ControllerSettingsController::class, 'testConnection'])->name('controller.test');
    Route::post('/controller/sync-sites', [ControllerSettingsController::class, 'syncSites'])->name('controller.sync-sites');
    Route::get('/access-points', [AccessPointController::class, 'index'])->name('access-points.index');
    Route::post('/access-points/sync', [AccessPointController::class, 'sync'])->name('access-points.sync');
    Route::post('/access-points/post-connection-fees', [AccessPointController::class, 'postConnectionFees'])->name('access-points.post-connection-fees');
    Route::post('/access-points/{accessPoint}/reverse-connection-fee', [AccessPointController::class, 'reverseConnectionFee'])->name('access-points.reverse-connection-fee');
    Route::post('/access-points/{accessPoint}/resolve-billing-incident', [AccessPointController::class, 'resolveBillingIncident'])->name('access-points.resolve-billing-incident');
    Route::post('/access-points/{accessPoint}/correct-ownership', [AccessPointController::class, 'correctOwnership'])->name('access-points.correct-ownership');
    Route::get('/access-point-claims', [AdminAccessPointClaimController::class, 'index'])->name('access-point-claims.index');
    Route::post('/access-point-claims/{accessPointClaim}/approve', [AdminAccessPointClaimController::class, 'approve'])->name('access-point-claims.approve');
    Route::post('/access-point-claims/{accessPointClaim}/deny', [AdminAccessPointClaimController::class, 'deny'])->name('access-point-claims.deny');
    Route::get('/plans', [PlanController::class, 'index'])->name('plans.index');
    Route::post('/plans', [PlanController::class, 'store'])->name('plans.store');
    Route::put('/plans/{plan}', [PlanController::class, 'update'])->name('plans.update');
    Route::delete('/plans/{plan}', [PlanController::class, 'destroy'])->name('plans.destroy');
    Route::get('/sessions', [SessionController::class, 'index'])->name('sessions.index');
    Route::post('/sessions/{wifiSession}/retry-release', [SessionController::class, 'retryRelease'])->name('sessions.retry-release');
    Route::post('/sessions/{wifiSession}/reconcile-release', [SessionController::class, 'reconcileRelease'])->name('sessions.reconcile-release');
    Route::get('/payments', [AdminPaymentController::class, 'index'])->name('payments.index');
    Route::get('/transfer-requests', [DeviceTransferRequestController::class, 'index'])->name('transfer-requests.index');
    Route::post('/transfer-requests/{deviceTransferRequest}/approve', [DeviceTransferRequestController::class, 'approve'])->name('transfer-requests.approve');
    Route::post('/transfer-requests/{deviceTransferRequest}/deny', [DeviceTransferRequestController::class, 'deny'])->name('transfer-requests.deny');
    Route::get('/operators', [AdminOperatorController::class, 'index'])->name('operators.index');
    Route::get('/operators/{operator}', [AdminOperatorController::class, 'show'])->name('operators.show');
    Route::put('/operators/{operator}/status', [AdminOperatorController::class, 'updateStatus'])->name('operators.status.update');
    Route::put('/operators/{operator}/sites', [AdminOperatorController::class, 'updateSites'])->name('operators.sites.update');
    Route::get('/payout-requests', [AdminPayoutRequestController::class, 'index'])->name('payout-requests.index');
    Route::post('/payout-requests/{payoutRequest}/approve', [AdminPayoutRequestController::class, 'approve'])->name('payout-requests.approve');
    Route::post('/payout-requests/{payoutRequest}/reject', [AdminPayoutRequestController::class, 'reject'])->name('payout-requests.reject');
    Route::post('/payout-requests/{payoutRequest}/processing', [AdminPayoutRequestController::class, 'markProcessing'])->name('payout-requests.processing');
    Route::post('/payout-requests/{payoutRequest}/paid', [AdminPayoutRequestController::class, 'markPaid'])->name('payout-requests.paid');
    Route::post('/payout-requests/{payoutRequest}/failed', [AdminPayoutRequestController::class, 'markFailed'])->name('payout-requests.failed');
});

Route::middleware(['auth', 'can:access-operator-panel'])->prefix('operator')->name('operator.')->group(function (): void {
    Route::get('/dashboard', OperatorDashboardController::class)->name('dashboard');
    Route::get('/devices', [DeviceController::class, 'index'])->name('devices.index');
    Route::post('/access-point-claims', [OperatorAccessPointClaimController::class, 'store'])
        ->middleware('throttle:operator-access-point-claims')
        ->name('access-point-claims.store');
    Route::post('/access-point-claims/{accessPointClaim}/adopt', [OperatorAccessPointClaimController::class, 'adopt'])->name('access-point-claims.adopt');
    Route::get('/payouts', [OperatorPayoutController::class, 'index'])->name('payouts.index');
    Route::post('/payouts', [OperatorPayoutController::class, 'store'])->name('payouts.store');
});
