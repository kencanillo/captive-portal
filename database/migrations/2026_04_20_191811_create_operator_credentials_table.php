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
        Schema::create('operator_credentials', function (Blueprint $table) {
            $table->id();
            $table->foreignId('operator_id')->constrained()->onDelete('cascade');
            $table->string('hotspot_operator_username')->unique();
            $table->string('hotspot_operator_password');
            $table->text('notes')->nullable();
            $table->timestamps();
            
            $table->index('operator_id');
            $table->index('hotspot_operator_username');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('operator_credentials');
    }
};
