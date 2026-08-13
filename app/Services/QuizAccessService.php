<?php

namespace App\Services;

use App\Models\User;
use App\Models\Wallet;
use Illuminate\Support\Facades\Log;

class QuizAccessService
{
    /**
     * Define quiz access rules mapping quiz types to their economic parameters.
     * Each rule contains:
     * - required_balance: minimum wallet balance to unlock
     * - entry_fee: cost to enter the quiz
     * - reward_points: points awarded for completion
     */
    private const QUIZ_ACCESS_RULES = [
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
     * Get access rules for a specific quiz type.
     *
     * @param string $quizType The quiz type (e.g., 'live_duel', 'multiple_choice')
     * @return array|null The access rules array or null if quiz type doesn't exist
     */
    public function getAccessRules(string $quizType): ?array
    {
        return self::QUIZ_ACCESS_RULES[$quizType] ?? null;
    }

    /**
     * Get all available access rules.
     *
     * @return array All defined access rules
     */
    public function getAllAccessRules(): array
    {
        return self::QUIZ_ACCESS_RULES;
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
