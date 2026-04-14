<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('controller_settings', function (Blueprint $table) {
            $table->id();
            $table->string('controller_name')->default('Primary Omada Controller');
            $table->string('base_url');
            $table->string('site_identifier')->nullable();
            $table->string('site_name')->nullable();
            $table->string('portal_base_url')->nullable();
            $table->string('username')->nullable();
            $table->text('password')->nullable();
            $table->string('api_client_id')->nullable();
            $table->text('api_client_secret')->nullable();
            $table->unsignedInteger('default_session_minutes')->default(60);
            $table->timestamp('last_tested_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('controller_settings');
    }
};
