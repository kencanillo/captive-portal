<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('access_points', function (Blueprint $table) {
            $table->string('serial_number')->nullable()->unique()->after('site_id');
            $table->string('omada_device_id')->nullable()->unique()->after('serial_number');
            $table->string('claim_status')->default('unclaimed')->index()->after('ip_address');
            $table->timestamp('claimed_at')->nullable()->after('claim_status');
            $table->timestamp('last_synced_at')->nullable()->after('claimed_at');
            $table->string('custom_ssid')->nullable()->after('last_synced_at');
            $table->string('voucher_ssid_name')->nullable()->after('custom_ssid');
            $table->boolean('allow_client_pause')->default(true)->after('voucher_ssid_name');
            $table->boolean('block_tethering')->default(true)->after('allow_client_pause');
            $table->boolean('is_portal_enabled')->default(true)->after('block_tethering');
        });
    }

    public function down(): void
    {
        Schema::table('access_points', function (Blueprint $table) {
            $table->dropColumn([
                'serial_number',
                'omada_device_id',
                'claim_status',
                'claimed_at',
                'last_synced_at',
                'custom_ssid',
                'voucher_ssid_name',
                'allow_client_pause',
                'block_tethering',
                'is_portal_enabled',
            ]);
        });
    }
};
