<?php

use App\Models\PayoutRequest;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payout_requests', function (Blueprint $table): void {
            $table->foreignId('invalidated_by_user_id')->nullable()->after('cancelled_by_user_id')->constrained('users')->nullOnDelete();
            $table->string('settlement_state')->default(PayoutRequest::SETTLEMENT_STATE_NOT_READY)->after('status')->index();
            $table->string('settlement_block_reason')->nullable()->after('settlement_state');
            $table->timestamp('settlement_checked_at')->nullable()->after('cancelled_at');
            $table->timestamp('settlement_ready_at')->nullable()->after('settlement_checked_at');
            $table->timestamp('invalidated_at')->nullable()->after('settlement_ready_at');
        });

        DB::table('payout_requests')
            ->where('status', PayoutRequest::STATUS_APPROVED)
            ->update([
                'settlement_state' => PayoutRequest::SETTLEMENT_STATE_READY,
            ]);

        DB::table('payout_requests')
            ->where('status', PayoutRequest::STATUS_PAID)
            ->update([
                'settlement_state' => PayoutRequest::SETTLEMENT_STATE_SETTLED,
            ]);

        DB::table('payout_requests')
            ->whereIn('status', [PayoutRequest::STATUS_PROCESSING, PayoutRequest::STATUS_FAILED])
            ->update([
                'settlement_state' => PayoutRequest::SETTLEMENT_STATE_BLOCKED_MANUAL_REVIEW,
                'settlement_block_reason' => PayoutRequest::SETTLEMENT_BLOCK_LEGACY_EXECUTION_STATUS,
            ]);
    }

    public function down(): void
    {
        Schema::table('payout_requests', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('invalidated_by_user_id');
            $table->dropColumn([
                'settlement_state',
                'settlement_block_reason',
                'settlement_checked_at',
                'settlement_ready_at',
                'invalidated_at',
            ]);
        });
    }
};
