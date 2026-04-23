<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        $password = Str::random(16);
        
        User::firstOrCreate(
            ['email' => 'admin@captive-portal.local'],
            [
                'name' => 'Administrator',
                'password' => bcrypt($password),
                'is_admin' => true,
            ]
        );
        
        $this->command->info('Admin user created:');
        $this->command->info('Email: admin@captive-portal.local');
        $this->command->info('Password: ' . $password);
    }
}
