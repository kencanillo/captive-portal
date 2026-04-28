<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('wifi_sessions', function (Blueprint $table): void {
            $table->string('source')->default('portal_payment')->after('authorization_source');
            $table->foreignId('authorized_by_user_id')->nullable()->after('source')->constrained('users')->nullOnDelete();
            $table->foreignId('operator_id')->nullable()->after('authorized_by_user_id')->constrained('operators')->nullOnDelete();
            $table->text('authorization_note')->nullable()->after('operator_id');
        });

        Schema::table('payments', function (Blueprint $table): void {
            $table->foreignId('created_by_user_id')->nullable()->after('wifi_session_id')->constrained('users')->nullOnDelete();
            $table->foreignId('operator_id')->nullable()->after('created_by_user_id')->constrained('operators')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('operator_id');
            $table->dropConstrainedForeignId('created_by_user_id');
        });

        Schema::table('wifi_sessions', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('operator_id');
            $table->dropConstrainedForeignId('authorized_by_user_id');
            $table->dropColumn([
                'source',
                'authorization_note',
            ]);
        });
    }
};
