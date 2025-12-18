<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class AdminUserSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Check if admin user already exists
        $adminUser = User::where('email', 'dev@dev.com')->first();
        
        if (!$adminUser) {
            User::create([
                'name' => 'Admin User',
                'email' => 'dev@dev.com',
                'password' => Hash::make('dev123456'),
                'role' => 'admin',
            ]);
        } else {
            // Update existing user to have admin role
            $adminUser->update([
                'role' => 'admin',
                'password' => Hash::make('dev123456'),
            ]);
        }
    }
}