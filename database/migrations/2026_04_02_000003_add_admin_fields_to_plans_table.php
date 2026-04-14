<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            $table->text('description')->nullable()->after('name');
            $table->boolean('is_active')->default(true)->after('speed_limit');
            $table->boolean('supports_pause')->default(true)->after('is_active');
            $table->boolean('enforce_no_tethering')->default(true)->after('supports_pause');
            $table->unsignedSmallInteger('sort_order')->default(0)->after('enforce_no_tethering');
        });
    }

    public function down(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            $table->dropColumn([
                'description',
                'is_active',
                'supports_pause',
                'enforce_no_tethering',
                'sort_order',
            ]);
        });
    }
};
