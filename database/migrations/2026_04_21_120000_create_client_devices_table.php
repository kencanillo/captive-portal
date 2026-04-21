<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('client_devices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->string('mac_address')->unique();
            $table->string('status')->default('bound')->index();
            $table->timestamp('first_seen_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();

            $table->unique(['client_id', 'mac_address']);
        });

        DB::table('clients')
            ->whereNotNull('mac_address')
            ->orderBy('id')
            ->chunkById(100, function ($clients): void {
                foreach ($clients as $client) {
                    $firstSeenAt = $client->last_connected_at ?? $client->created_at ?? now();
                    $lastSeenAt = $client->last_connected_at ?? $client->updated_at ?? now();

                    DB::table('client_devices')->updateOrInsert(
                        ['mac_address' => strtolower($client->mac_address)],
                        [
                            'client_id' => $client->id,
                            'status' => 'bound',
                            'first_seen_at' => $firstSeenAt,
                            'last_seen_at' => $lastSeenAt,
                            'created_at' => $client->created_at ?? now(),
                            'updated_at' => $client->updated_at ?? now(),
                        ]
                    );
                }
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_devices');
    }
};
