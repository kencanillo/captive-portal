<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('access_point_claims', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('operator_id')->constrained()->cascadeOnDelete();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->string('requested_serial_number')->nullable();
            $table->string('requested_serial_number_normalized')->nullable();
            $table->string('requested_mac_address')->nullable();
            $table->string('requested_mac_address_normalized')->nullable();
            $table->string('requested_ap_name')->nullable();
            $table->string('claim_status')->default('pending_review');
            $table->timestamp('claimed_at')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->foreignId('reviewed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('review_notes')->nullable();
            $table->text('denial_reason')->nullable();
            $table->foreignId('matched_access_point_id')->nullable()->constrained('access_points')->nullOnDelete();
            $table->string('matched_omada_device_id')->nullable();
            $table->timestamp('adoption_attempted_at')->nullable();
            $table->json('adoption_result_metadata')->nullable();
            $table->text('failure_reason')->nullable();
            $table->timestamps();

            $table->index(['operator_id', 'claim_status']);
            $table->index(['site_id', 'claim_status']);
            $table->index('requested_serial_number_normalized', 'access_point_claims_serial_lookup_index');
            $table->index('requested_mac_address_normalized', 'access_point_claims_mac_lookup_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('access_point_claims');
    }
};
