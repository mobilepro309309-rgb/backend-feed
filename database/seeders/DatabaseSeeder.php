<?php

namespace Database\Seeders;

use App\Models\User;
use Database\Seeders\DistrictSeeder;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call([DistrictSeeder::class]);

        User::factory()->create([
            'name' => 'Main Admin',
            'email' => 'main.admin@example.com',
            'role' => 'main-admin',
        ]);

        User::factory()->create([
            'name' => 'Reply Questions Admin',
            'email' => 'reply.admin@example.com',
            'role' => 'reply_questions_admin',
        ]);

        User::factory()->create([
            'name' => 'Question Post Admin',
            'email' => 'post.admin@example.com',
            'role' => 'question_post_admin',
        ]);

        User::factory()->create([
            'name' => 'Financial Admin',
            'email' => 'financial.admin@example.com',
            'role' => 'financial_admin',
        ]);

        User::factory()->create([
            'name' => 'Technical Support Admin',
            'email' => 'support.admin@example.com',
            'role' => 'technical_support_admin',
        ]);

        User::factory()->create([
            'name' => 'Regular User',
            'email' => 'user@example.com',
            'role' => 'user',
        ]);
    }
}
