<?php

namespace Database\Seeders;

use App\Models\QuestionTypeSetting;
use Illuminate\Database\Seeder;

class QuestionTypeSettingSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $settings = [
            [
                'question_type' => 'true_false',
                'reward_points' => 2,
                'entry_fee' => 0,
                'is_active' => true,
            ],
            [
                'question_type' => 'multiple_choice',
                'reward_points' => 2,
                'entry_fee' => 0,
                'is_active' => true,
            ],
            [
                'question_type' => 'daily_challenge',
                'reward_points' => 12,
                'entry_fee' => 4,
                'is_active' => true,
            ],
            [
                'question_type' => 'find_the_bug',
                'reward_points' => 6,
                'entry_fee' => 3,
                'is_active' => true,
            ],
            [
                'question_type' => 'live_duel',
                'reward_points' => 15,
                'entry_fee' => 5,
                'is_active' => true,
            ],
            [
                'question_type' => 'comparison_card',
                'reward_points' => 3,
                'entry_fee' => 0,
                'is_active' => true,
            ],
            [
                'question_type' => 'cloud_capsule',
                'reward_points' => 6,
                'entry_fee' => 0,
                'is_active' => true,
            ],
            [
                'question_type' => 'cheat_sheet',
                'reward_points' => 6,
                'entry_fee' => 3,
                'is_active' => true,
            ],
        ];

        foreach ($settings as $setting) {
            QuestionTypeSetting::updateOrCreate(
                ['question_type' => $setting['question_type']],
                $setting
            );
        }
    }
}
