<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('access_point_ownership_corrections')) {
            Schema::create('access_point_ownership_corrections', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('access_point_id')->constrained()->cascadeOnDelete();
                $table->foreignId('from_operator_id')->nullable()->constrained('operators')->nullOnDelete();
                $table->foreignId('to_operator_id')->nullable()->constrained('operators')->nullOnDelete();
                $table->foreignId('from_site_id')->nullable()->constrained('sites')->nullOnDelete();
                $table->foreignId('to_site_id')->nullable()->constrained('sites')->nullOnDelete();
                $table->foreignId('from_approved_claim_id')->nullable()->constrained('access_point_claims', indexName: 'apoc_from_claim_fk')->nullOnDelete();
                $table->foreignId('corrected_by_user_id')->constrained('users')->cascadeOnDelete();
                $table->text('correction_reason');
                $table->text('notes')->nullable();
                $table->json('metadata')->nullable();
                $table->timestamp('corrected_at');
                $table->timestamps();

                $table->index(['access_point_id', 'corrected_at'], 'access_point_ownership_corrections_lookup_index');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('access_point_ownership_corrections');
    }
};
