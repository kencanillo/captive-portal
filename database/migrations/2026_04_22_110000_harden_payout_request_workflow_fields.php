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
            $table->foreignId('cancelled_by_user_id')->nullable()->after('reviewed_by_user_id')->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable()->after('reviewed_at');
            $table->text('cancellation_reason')->nullable()->after('review_notes');
            $table->json('metadata')->nullable()->after('destination_snapshot');
        });

        DB::table('payout_requests')
            ->where('status', 'pending')
            ->update(['status' => PayoutRequest::STATUS_PENDING_REVIEW]);
    }

    public function down(): void
    {
        DB::table('payout_requests')
            ->where('status', PayoutRequest::STATUS_PENDING_REVIEW)
            ->update(['status' => 'pending']);

        Schema::table('payout_requests', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('cancelled_by_user_id');
            $table->dropColumn([
                'cancelled_at',
                'cancellation_reason',
                'metadata',
            ]);
        });
    }
};
