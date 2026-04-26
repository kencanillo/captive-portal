<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('access_points', function (Blueprint $table): void {
            $table->timestamp('ownership_corrected_at')->nullable()->after('ownership_verified_at');
            $table->foreignId('ownership_corrected_by_user_id')->nullable()->after('ownership_corrected_at')->constrained('users')->nullOnDelete();
            $table->text('latest_correction_reason')->nullable()->after('ownership_corrected_by_user_id');
        });
    }

    public function down(): void
    {
        Schema::table('access_points', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('ownership_corrected_by_user_id');
            $table->dropColumn([
                'ownership_corrected_at',
                'latest_correction_reason',
            ]);
        });
    }
};
