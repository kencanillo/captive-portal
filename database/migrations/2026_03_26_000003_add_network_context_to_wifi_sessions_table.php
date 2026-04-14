<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('wifi_sessions', function (Blueprint $table) {
            $table->foreignId('site_id')->nullable()->after('plan_id')->constrained()->nullOnDelete();
            $table->foreignId('access_point_id')->nullable()->after('site_id')->constrained()->nullOnDelete();
            $table->string('ap_mac')->nullable()->after('access_point_id')->index();
            $table->string('ap_name')->nullable()->after('ap_mac');
            $table->string('ssid_name')->nullable()->after('ap_name')->index();
            $table->string('client_ip')->nullable()->after('ssid_name');
        });
    }

    public function down(): void
    {
        Schema::table('wifi_sessions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('access_point_id');
            $table->dropConstrainedForeignId('site_id');
            $table->dropColumn([
                'ap_mac',
                'ap_name',
                'ssid_name',
                'client_ip',
            ]);
        });
    }
};
