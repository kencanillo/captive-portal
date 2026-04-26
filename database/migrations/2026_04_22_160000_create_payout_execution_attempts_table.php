<?php

use App\Models\PayoutExecutionAttempt;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payout_execution_attempts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('payout_request_id')->constrained()->cascadeOnDelete();
            $table->foreignId('payout_settlement_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('operator_id')->constrained()->cascadeOnDelete();
            $table->decimal('amount', 12, 2);
            $table->string('currency', 3);
            $table->string('execution_state')->default(PayoutExecutionAttempt::STATE_PENDING_EXECUTION)->index();
            $table->string('execution_reference')->unique();
            $table->string('idempotency_key')->unique();
            $table->string('external_reference')->nullable()->index();
            $table->timestamp('triggered_at');
            $table->foreignId('triggered_by_user_id')->constrained('users')->restrictOnDelete();
            $table->string('provider_name')->nullable();
            $table->json('provider_request_metadata')->nullable();
            $table->json('provider_response_metadata')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['payout_request_id', 'execution_state'], 'payout_execution_attempts_request_state_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payout_execution_attempts');
    }
};
