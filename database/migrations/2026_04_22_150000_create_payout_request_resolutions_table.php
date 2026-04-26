<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payout_request_resolutions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('payout_request_id')->constrained()->cascadeOnDelete();
            $table->foreignId('operator_id')->constrained()->cascadeOnDelete();
            $table->string('resolution_type');
            $table->timestamp('resolved_at');
            $table->foreignId('resolved_by_user_id')->constrained('users')->restrictOnDelete();
            $table->string('reason', 255);
            $table->text('notes')->nullable();
            $table->string('resulting_status');
            $table->string('resulting_settlement_state');
            $table->json('metadata')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payout_request_resolutions');
    }
};
