<?php

namespace App\Services;

use App\Models\QuestionTypeSetting;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Support\Facades\Log;

class QuizAccessService
{
    /**
     * Fallback hardcoded rules (used when database records don't exist).
     * These serve as the default values and ensure system stability if database is missing config.
     * Each rule contains:
     * - required_balance: minimum wallet balance to unlock
     * - entry_fee: cost to enter the quiz
     * - reward_points: points awarded for completion
     */
    private const FALLBACK_QUIZ_ACCESS_RULES = [
        'multiple_choice' => [
            'required_balance' => 0,
            'entry_fee' => 0,
            'reward_points' => 2,
        ],
        'true_false' => [
            'required_balance' => 0,
            'entry_fee' => 0,
            'reward_points' => 2,
        ],
        'comparison_card' => [
            'required_balance' => 0,
            'entry_fee' => 0,
            'reward_points' => 3,
        ],
        'live_duel' => [
            'required_balance' => 20,
            'entry_fee' => 5,
            'reward_points' => 15,
        ],
        'find_the_bug' => [
            'required_balance' => 15,
            'entry_fee' => 3,
            'reward_points' => 6,
        ],
        'cloud_capsule' => [
            'required_balance' => 0,
            'entry_fee' => 0,
            'reward_points' => 6,
        ],
        'cheat_sheet' => [
            'required_balance' => 15,
            'entry_fee' => 3,
            'reward_points' => 6,
        ],
        'daily_challenge' => [
            'required_balance' => 15,
            'entry_fee' => 4,
            'reward_points' => 12,
        ],
    ];

    /**
     * In-memory cache for question type settings (per request lifecycle).
     * Stores all active settings to minimize database queries.
     *
     * @var array|null
     */
    private ?array $cachedSettings = null;

    /**
     * Load all active settings from database and cache them.
     * Minimizes database queries within a single request lifecycle.
     *
     * @return array Associative array of question_type => {reward_points, entry_fee}
     */
    private function loadAndCacheSettings(): array
    {
        if ($this->cachedSettings !== null) {
            return $this->cachedSettings;
        }

        try {
            $this->cachedSettings = [];
            $settings = QuestionTypeSetting::getAllActive();

            foreach ($settings as $setting) {
                $this->cachedSettings[$setting->question_type] = [
                    'reward_points' => $setting->reward_points,
                    'entry_fee' => $setting->entry_fee,
                ];
            }

            return $this->cachedSettings;
        } catch (\Exception $e) {
            Log::error('Failed to load question type settings from database', [
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * Get the reward points value for a question type from database or fallback.
     * First tries the database, then falls back to hardcoded values.
     *
     * @param string $quizType The quiz type
     * @return int The reward points value
     */
    private function getRewardPointsForType(string $quizType): int
    {
        $cachedSettings = $this->loadAndCacheSettings();

        if (isset($cachedSettings[$quizType])) {
            return (int) $cachedSettings[$quizType]['reward_points'];
        }

        // Fallback to hardcoded values
        if (isset(self::FALLBACK_QUIZ_ACCESS_RULES[$quizType])) {
            return (int) self::FALLBACK_QUIZ_ACCESS_RULES[$quizType]['reward_points'];
        }

        return 0;
    }

    /**
     * Get the entry fee for a question type from database or fallback.
     * First tries the database, then falls back to hardcoded values.
     *
     * @param string $quizType The quiz type
     * @return int The entry fee value
     */
    private function getEntryFeeForType(string $quizType): int
    {
        $cachedSettings = $this->loadAndCacheSettings();

        if (isset($cachedSettings[$quizType])) {
            return (int) $cachedSettings[$quizType]['entry_fee'];
        }

        // Fallback to hardcoded values
        if (isset(self::FALLBACK_QUIZ_ACCESS_RULES[$quizType])) {
            return (int) self::FALLBACK_QUIZ_ACCESS_RULES[$quizType]['entry_fee'];
        }

        return 0;
    }

    /**
     * Get access rules for a specific quiz type (database-driven with fallback).
     * Constructs the rules array dynamically using database values for reward_points and entry_fee
     * and fallback constants for other fields.
     *
     * @param string $quizType The quiz type (e.g., 'live_duel', 'multiple_choice')
     * @return array|null The access rules array or null if quiz type doesn't exist
     */
    public function getAccessRules(string $quizType): ?array
    {
        // Use fallback rules as base
        if (!isset(self::FALLBACK_QUIZ_ACCESS_RULES[$quizType])) {
            return null;
        }

        $fallbackRules = self::FALLBACK_QUIZ_ACCESS_RULES[$quizType];

        // Get reward_points and entry_fee from database (with database fallback to hardcoded)
        $rewardPoints = $this->getRewardPointsForType($quizType);
        $entryFee = $this->getEntryFeeForType($quizType);

        return [
            'required_balance' => $fallbackRules['required_balance'],
            'entry_fee' => $entryFee,
            'reward_points' => $rewardPoints,
        ];
    }

    /**
     * Get all available access rules (database-driven with fallback).
     *
     * @return array All defined access rules with database-driven points values
     */
    public function getAllAccessRules(): array
    {
        $allRules = [];

        foreach (self::FALLBACK_QUIZ_ACCESS_RULES as $quizType => $fallbackRule) {
            $rules = $this->getAccessRules($quizType);
            if ($rules) {
                $allRules[$quizType] = $rules;
            }
        }

        return $allRules;
    }

    /**
     * Build access rules object for a quiz item to append to API responses.
     * Includes information about whether the user can unlock and has unlocked the quiz.
     *
     * @param string $quizType The quiz type
     * @param User|null $user The authenticated user (null if unauthenticated)
     * @return array|null The access rules object or null if quiz type doesn't exist
     */
    public function buildAccessRulesObject(string $quizType, ?User $user = null): ?array
    {
        $rules = $this->getAccessRules($quizType);

        if (!$rules) {
            Log::warning('Quiz access rules requested for unknown quiz type', [
                'quiz_type' => $quizType,
            ]);

            return null;
        }

        $userBalance = 0;
        $canUnlock = false;

        if ($user && $user->wallet) {
            $userBalance = (float) $user->wallet->balance;
            $canUnlock = $userBalance >= (float) $rules['required_balance'];
        }

        return [
            'quiz_type' => $quizType,
            'required_balance' => (int) $rules['required_balance'],
            'entry_fee' => (int) $rules['entry_fee'],
            'reward_points' => (int) $rules['reward_points'],
            'can_unlock' => $canUnlock,
            'is_unlocked' => false,  // This will be set based on whether user has entered before
            'current_balance' => $userBalance,
        ];
    }

    /**
     * Check if a user can unlock a quiz (has sufficient balance).
     *
     * @param User $user The user to check
     * @param string $quizType The quiz type
     * @return array Response with 'can_unlock' bool and 'reason' string if cannot unlock
     */
    public function canUserUnlockQuiz(User $user, string $quizType): array
    {
        $rules = $this->getAccessRules($quizType);

        if (!$rules) {
            return [
                'can_unlock' => false,
                'reason' => 'Invalid quiz type',
            ];
        }

        if (!$user->wallet) {
            return [
                'can_unlock' => false,
                'reason' => 'User wallet not found',
            ];
        }

        $currentBalance = (float) $user->wallet->balance;
        $requiredBalance = (float) $rules['required_balance'];

        if ($currentBalance < $requiredBalance) {
            return [
                'can_unlock' => false,
                'reason' => "Insufficient balance. Required: {$requiredBalance}, Current: {$currentBalance}",
                'required_balance' => $requiredBalance,
                'current_balance' => $currentBalance,
                'shortage' => $requiredBalance - $currentBalance,
            ];
        }

        return [
            'can_unlock' => true,
        ];
    }

    /**
     * Check if a user can afford the entry fee for a quiz.
     *
     * @param User $user The user to check
     * @param string $quizType The quiz type
     * @return array Response with 'can_afford' bool and details
     */
    public function canUserAffordEntry(User $user, string $quizType): array
    {
        $rules = $this->getAccessRules($quizType);

        if (!$rules) {
            return [
                'can_afford' => false,
                'reason' => 'Invalid quiz type',
            ];
        }

        if (!$user->wallet) {
            return [
                'can_afford' => false,
                'reason' => 'User wallet not found',
            ];
        }

        $currentBalance = (float) $user->wallet->balance;
        $entryFee = (float) $rules['entry_fee'];

        if ($currentBalance < $entryFee) {
            return [
                'can_afford' => false,
                'reason' => "Insufficient balance to pay entry fee. Required: {$entryFee}, Current: {$currentBalance}",
                'entry_fee' => $entryFee,
                'current_balance' => $currentBalance,
                'shortage' => $entryFee - $currentBalance,
            ];
        }

        return [
            'can_afford' => true,
            'entry_fee' => $entryFee,
            'current_balance' => $currentBalance,
        ];
    }

    /**
     * Get reward points for completing a quiz.
     *
     * @param string $quizType The quiz type
     * @return int|null The reward points or null if quiz type doesn't exist
     */
    public function getRewardPoints(string $quizType): ?int
    {
        $rules = $this->getAccessRules($quizType);

        return $rules ? (int) $rules['reward_points'] : null;
    }

    /**
     * Get entry fee for a quiz.
     *
     * @param string $quizType The quiz type
     * @return int|null The entry fee or null if quiz type doesn't exist
     */
    public function getEntryFee(string $quizType): ?int
    {
        $rules = $this->getAccessRules($quizType);

        return $rules ? (int) $rules['entry_fee'] : null;
    }

    /**
     * Get required balance for a quiz.
     *
     * @param string $quizType The quiz type
     * @return int|null The required balance or null if quiz type doesn't exist
     */
    public function getRequiredBalance(string $quizType): ?int
    {
        $rules = $this->getAccessRules($quizType);

        return $rules ? (int) $rules['required_balance'] : null;
    }
}
