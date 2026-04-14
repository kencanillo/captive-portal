<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('wifi_sessions', function (Blueprint $table) {
            $table->id();
            $table->string('mac_address')->index();
            $table->foreignId('plan_id')->constrained()->cascadeOnDelete();
            $table->decimal('amount_paid', 10, 2);
            $table->enum('payment_status', ['pending', 'paid', 'failed'])->default('pending')->index();
            $table->timestamp('start_time')->nullable();
            $table->timestamp('end_time')->nullable();
            $table->boolean('is_active')->default(false)->index();
            $table->string('paymongo_payment_intent_id')->nullable()->index();
            $table->timestamps();

            $table->index(['mac_address', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wifi_sessions');
    }
};
