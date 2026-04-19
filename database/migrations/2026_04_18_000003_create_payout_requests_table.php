<?php

use App\Models\PayoutRequest;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payout_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('operator_id')->constrained()->cascadeOnDelete();
            $table->foreignId('reviewed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->decimal('amount', 12, 2);
            $table->string('currency', 3)->default('PHP');
            $table->string('status')->default(PayoutRequest::STATUS_PENDING)->index();
            $table->timestamp('requested_at')->nullable()->index();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->text('notes')->nullable();
            $table->text('review_notes')->nullable();
            $table->string('destination_type')->nullable();
            $table->string('destination_account_name')->nullable();
            $table->string('destination_account_reference')->nullable();
            $table->json('destination_snapshot')->nullable();
            $table->string('processing_mode')->nullable()->index();
            $table->string('provider')->nullable()->index();
            $table->string('provider_transfer_reference')->nullable()->index();
            $table->string('provider_status')->nullable();
            $table->json('provider_response')->nullable();
            $table->text('failure_reason')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payout_requests');
    }
};
