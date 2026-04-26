<?php

use App\Models\PayoutSettlementCorrection;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payout_settlement_corrections', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('payout_settlement_id')->unique();
            $table->foreign('payout_settlement_id', 'psc_settlement_fk')
                ->references('id')
                ->on('payout_settlements')
                ->cascadeOnDelete();
            $table->foreignId('payout_request_id');
            $table->foreign('payout_request_id', 'psc_request_fk')
                ->references('id')
                ->on('payout_requests')
                ->cascadeOnDelete();
            $table->foreignId('operator_id');
            $table->foreign('operator_id', 'psc_operator_fk')
                ->references('id')
                ->on('operators')
                ->cascadeOnDelete();
            $table->string('correction_type')->default(PayoutSettlementCorrection::TYPE_REVERSAL);
            $table->timestamp('corrected_at');
            $table->foreignId('corrected_by_user_id');
            $table->foreign('corrected_by_user_id', 'psc_corrected_by_fk')
                ->references('id')
                ->on('users')
                ->restrictOnDelete();
            $table->string('reason', 255);
            $table->text('notes')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payout_settlement_corrections');
    }
};
