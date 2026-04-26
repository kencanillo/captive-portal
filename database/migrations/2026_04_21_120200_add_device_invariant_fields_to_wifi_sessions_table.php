<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('wifi_sessions', function (Blueprint $table) {
            $table->foreignId('client_device_id')->nullable()->after('client_id')->constrained('client_devices')->nullOnDelete();
            $table->foreignId('extends_session_id')->nullable()->after('paymongo_payment_intent_id')->constrained('wifi_sessions')->nullOnDelete();
            $table->foreignId('merged_into_session_id')->nullable()->after('extends_session_id')->constrained('wifi_sessions')->nullOnDelete();
        });

        DB::table('wifi_sessions')
            ->whereNotNull('client_id')
            ->whereNotNull('mac_address')
            ->orderBy('id')
            ->chunkById(100, function ($sessions): void {
                foreach ($sessions as $session) {
                    $deviceId = DB::table('client_devices')
                        ->where('client_id', $session->client_id)
                        ->where('mac_address', strtolower($session->mac_address))
                        ->value('id');

                    if ($deviceId) {
                        DB::table('wifi_sessions')
                            ->where('id', $session->id)
                            ->update(['client_device_id' => $deviceId]);
                    }
                }
            });
    }

    public function down(): void
    {
        Schema::table('wifi_sessions', function (Blueprint $table) {
            $table->dropForeign(['merged_into_session_id']);
            $table->dropColumn('merged_into_session_id');
            $table->dropForeign(['extends_session_id']);
            $table->dropColumn('extends_session_id');
            $table->dropForeign(['client_device_id']);
            $table->dropColumn('client_device_id');
        });
    }
};
