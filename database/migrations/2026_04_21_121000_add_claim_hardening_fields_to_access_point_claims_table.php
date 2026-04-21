<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('access_point_claims', function (Blueprint $table): void {
            $table->string('claim_match_status')->nullable()->after('claim_status');
            $table->json('match_snapshot')->nullable()->after('matched_omada_device_id');
            $table->timestamp('matched_at')->nullable()->after('match_snapshot');
            $table->boolean('requires_re_review')->default(false)->after('matched_at');
            $table->string('conflict_state')->nullable()->after('requires_re_review');
            $table->timestamp('sync_freshness_checked_at')->nullable()->after('conflict_state');

            $table->index(['claim_status', 'requires_re_review'], 'access_point_claims_rereview_index');
            $table->index(['claim_status', 'conflict_state'], 'access_point_claims_conflict_index');
        });
    }

    public function down(): void
    {
        Schema::table('access_point_claims', function (Blueprint $table): void {
            $table->dropIndex('access_point_claims_rereview_index');
            $table->dropIndex('access_point_claims_conflict_index');
            $table->dropColumn([
                'claim_match_status',
                'match_snapshot',
                'matched_at',
                'requires_re_review',
                'conflict_state',
                'sync_freshness_checked_at',
            ]);
        });
    }
};
