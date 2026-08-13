<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserQuizAttempt extends Model
{
    use HasFactory;

    protected $table = 'user_quiz_attempts';

    protected $fillable = [
        'user_id',
        'quiz_type',
        'quiz_id',
        'user_answer',
        'is_correct',
    ];

    protected function casts(): array
    {
        return [
            'is_correct' => 'boolean',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    /**
     * Get the user that owns this attempt
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Normalize feed/UI quiz type strings into the canonical database values.
     *
     * Example:
     *   true-false-question => true_false
     *   find-the-bug-challenge => find_the_bug
     */
    public static function normalizeQuizType(string $quizType): string
    {
        $normalized = strtolower(trim($quizType));

        $typeMap = [
            'true-false-question' => 'true_false',
            'find-the-bug-challenge' => 'find_the_bug',
            'daily-challenge' => 'daily_challenge',
            'multiple-choice-question' => 'multiple_choice',
            'live-duel-challenge' => 'live_duel',
            'comparison-challenge' => 'comparison_card',
            'cloud-capsule-challenge' => 'cloud_capsule',
            'true_false' => 'true_false',
            'find_the_bug' => 'find_the_bug',
            'daily_challenge' => 'daily_challenge',
            'multiple_choice' => 'multiple_choice',
            'live_duel' => 'live_duel',
            'comparison_card' => 'comparison_card',
            'cloud_capsule' => 'cloud_capsule',
        ];

        return $typeMap[$normalized] ?? str_replace('-', '_', $normalized);
    }

    /**
     * Check if a user has already attempted a quiz
     *
     * @param int $userId
     * @param string $quizType
     * @param int $quizId
     * @return bool
     */
    public static function hasUserAttempted(int $userId, string $quizType, int $quizId): bool
    {
        $dbQuizType = self::normalizeQuizType($quizType);

        return self::where('user_id', $userId)
            ->where('quiz_type', $dbQuizType)
            ->where('quiz_id', $quizId)
            ->exists();
    }

    /**
     * Get the existing attempt for a user quiz
     *
     * @param int $userId
     * @param string $quizType
     * @param int $quizId
     * @return self|null
     */
    public static function getUserAttempt(int $userId, string $quizType, int $quizId): ?self
    {
        $dbQuizType = self::normalizeQuizType($quizType);

        return self::where('user_id', $userId)
            ->where('quiz_type', $dbQuizType)
            ->where('quiz_id', $quizId)
            ->first();
    }
}
