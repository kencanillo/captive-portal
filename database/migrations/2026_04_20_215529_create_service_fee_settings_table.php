<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('service_fee_settings', function (Blueprint $table) {
            $table->id();
            $table->enum('type', ['site_wide', 'operator_specific', 'revenue_tier'])->default('site_wide');
            $table->foreignId('operator_id')->nullable()->constrained()->onDelete('cascade');
            $table->decimal('fee_rate', 8, 4)->default(0.0500); // 5% default
            $table->decimal('revenue_threshold_min', 12, 2)->nullable(); // For revenue tiers
            $table->decimal('revenue_threshold_max', 12, 2)->nullable(); // For revenue tiers
            $table->boolean('is_active')->default(true);
            $table->text('description')->nullable();
            $table->timestamps();
            
            $table->index(['type', 'is_active']);
            $table->index('operator_id');
            $table->index(['revenue_threshold_min', 'revenue_threshold_max'], 'revenue_threshold_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('service_fee_settings');
    }
};
