<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class QuestionTypeSetting extends Model
{
    use HasFactory;

    protected $table = 'question_type_settings';

    protected $fillable = [
        'question_type',
        'reward_points',
        'entry_fee',
        'is_active',
    ];

    protected $casts = [
        'reward_points' => 'integer',
        'entry_fee' => 'integer',
        'is_active' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Get the setting for a specific question type.
     *
     * @param string $type The question type (e.g., 'true_false', 'multiple_choice')
     * @return self|null
     */
    public static function getByType(string $type): ?self
    {
        return self::where('question_type', $type)
            ->where('is_active', true)
            ->first();
    }

    /**
     * Get the point value for a specific question type.
     *
     * @param string $type The question type
     * @param int $default Default value if not found
     * @return int
     */
    public static function getRewardPoints(string $type, int $default = 1): int
    {
        $setting = self::getByType($type);

        return $setting?->reward_points ?? $default;
    }

    /**
     * Get the entry fee for a specific question type.
     *
     * @param string $type The question type
     * @param int $default Default value if not found
     * @return int
     */
    public static function getEntryFee(string $type, int $default = 0): int
    {
        $setting = self::getByType($type);

        return $setting?->entry_fee ?? $default;
    }

    /**
     * Get all active settings.
     *
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public static function getAllActive()
    {
        return self::where('is_active', true)
            ->orderBy('question_type')
            ->get();
    }

    /**
     * Check if a setting exists for the given question type.
     *
     * @param string $type The question type
     * @return bool
     */
    public static function typeExists(string $type): bool
    {
        return self::where('question_type', $type)->exists();
    }
}
