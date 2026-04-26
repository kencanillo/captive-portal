<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('wifi_sessions', function (Blueprint $table): void {
            $table->timestamp('authorized_at')->nullable()->after('release_metadata');
            $table->timestamp('deauthorized_at')->nullable()->after('authorized_at');
            $table->string('authorization_source')->nullable()->after('deauthorized_at');
            $table->timestamp('last_controller_seen_at')->nullable()->after('authorization_source');
        });
    }

    public function down(): void
    {
        Schema::table('wifi_sessions', function (Blueprint $table): void {
            $table->dropColumn([
                'authorized_at',
                'deauthorized_at',
                'authorization_source',
                'last_controller_seen_at',
            ]);
        });
    }
};
