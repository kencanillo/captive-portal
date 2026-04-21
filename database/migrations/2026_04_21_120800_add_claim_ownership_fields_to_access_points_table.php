<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('access_points', function (Blueprint $table): void {
            $table->foreignId('claimed_by_operator_id')->nullable()->after('site_id')->constrained('operators')->nullOnDelete();
            $table->foreignId('approved_claim_id')->nullable()->after('claimed_by_operator_id')->constrained('access_point_claims')->nullOnDelete();
            $table->string('adoption_state')->nullable()->after('claim_status');
            $table->timestamp('ownership_verified_at')->nullable()->after('claimed_at');
            $table->foreignId('ownership_verified_by_user_id')->nullable()->after('ownership_verified_at')->constrained('users')->nullOnDelete();
            $table->timestamp('first_connected_at')->nullable()->after('last_seen_at');

            $table->index(['claimed_by_operator_id', 'adoption_state'], 'access_points_operator_adoption_state_index');
        });
    }

    public function down(): void
    {
        Schema::table('access_points', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('claimed_by_operator_id');
            $table->dropConstrainedForeignId('approved_claim_id');
            $table->dropConstrainedForeignId('ownership_verified_by_user_id');
            $table->dropIndex('access_points_operator_adoption_state_index');
            $table->dropColumn([
                'adoption_state',
                'ownership_verified_at',
                'first_connected_at',
            ]);
        });
    }
};
