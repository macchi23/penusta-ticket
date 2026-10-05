<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        $user = User::firstOrCreate(
            ['email' => 'admin@penusta.example'],
            ['name' => '管理者', 'password' => Str::random(40)],
        );

        if ($user->role !== UserRole::Admin) {
            $user->role = UserRole::Admin;
            $user->save();
        }
    }
}
