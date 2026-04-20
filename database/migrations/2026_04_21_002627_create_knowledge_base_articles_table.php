<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('knowledge_base_articles', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->string('slug')->unique();
            $table->text('excerpt')->nullable();
            $table->longText('content');
            $table->string('category');
            $table->json('tags')->nullable();
            $table->integer('views')->default(0);
            $table->boolean('is_published')->default(false);
            $table->boolean('is_featured')->default(false);
            $table->integer('sort_order')->default(0);
            $table->foreignId('created_by')->constrained('users');
            $table->foreignId('updated_by')->nullable()->constrained('users');
            $table->timestamps();

            $table->index(['category', 'is_published']);
            $table->index(['is_featured', 'is_published']);
            
            // Only create fulltext index for MySQL/MariaDB (SQLite doesn't support it)
            if (DB::getDriverName() !== 'sqlite') {
                $table->fullText(['title', 'content']);
            }
        });
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            Schema::table('knowledge_base_articles', function (Blueprint $table) {
                $table->dropFullText(['title', 'content']);
            });
        }
        Schema::dropIfExists('knowledge_base_articles');
    }
};
