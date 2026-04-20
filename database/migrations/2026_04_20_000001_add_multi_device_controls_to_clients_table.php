<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table): void {
            $table->string('normalized_phone_number')->nullable()->after('phone_number')->index();
            $table->timestamp('last_transferred_at')->nullable()->after('last_connected_at');
        });

        DB::table('clients')
            ->select(['id', 'phone_number'])
            ->orderBy('id')
            ->chunkById(100, function ($clients): void {
                foreach ($clients as $client) {
                    DB::table('clients')
                        ->where('id', $client->id)
                        ->update([
                            'normalized_phone_number' => preg_replace('/\D+/', '', (string) $client->phone_number) ?: null,
                        ]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table): void {
            $table->dropColumn(['normalized_phone_number', 'last_transferred_at']);
        });
    }
};
