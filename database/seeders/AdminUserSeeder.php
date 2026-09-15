<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class AdminUserSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        User::updateOrCreate(
            [
                'email' => 'admin@comite.local',
            ],
            [
                'name' => 'Administrador',
                'password' => Hash::make('Admin12345'),
                'role' => 'admin',
                'active' => true,
            ]
        );
    }
}