<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\WalletTransaction;
use App\Models\UserQuizAttempt;
use App\Services\QuizAccessService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class QuizTransactionController extends Controller
{
    public function __construct(private QuizAccessService $quizAccessService)
    {
    }

    /**
     * POST /api/quiz/unlock
     * Deduct entry fee from user wallet and record the transaction.
     * Uses database transaction for atomicity.
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function unlock(Request $request)
    {
        try {
            $user = $request->user();

            if (!$user) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Unauthenticated',
                ], 401);
            }

            // Validate request
            $validated = $request->validate([
                'quiz_type' => ['required', 'string'],
                'quiz_id' => ['required', 'integer'],
            ]);

            $quizType = $validated['quiz_type'];
            $quizId = $validated['quiz_id'];

            // Get the quiz access rules
            $rules = $this->quizAccessService->getAccessRules($quizType);
            if (!$rules) {
                return response()->json([
                    'status' => 'error',
                    'message' => "Invalid quiz type: {$quizType}",
                ], 400);
            }

            $entryFee = (float) $rules['entry_fee'];

            // If no entry fee, return success immediately
            if ($entryFee === 0.0) {
                return response()->json([
                    'status' => 'success',
                    'message' => 'Quiz unlocked (no fee required)',
                    'balance' => (float) $user->wallet->balance,
                    'entry_fee' => $entryFee,
                ]);
            }

            // Ensure user has a wallet
            if (!$user->wallet) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'User wallet not found',
                ], 500);
            }

            // Check if user can afford the entry fee
            $affordabilityCheck = $this->quizAccessService->canUserAffordEntry($user, $quizType);
            if (!$affordabilityCheck['can_afford']) {
                return response()->json([
                    'status' => 'error',
                    'message' => $affordabilityCheck['reason'],
                    'current_balance' => $affordabilityCheck['current_balance'],
                    'required_amount' => $affordabilityCheck['entry_fee'],
                    'shortage' => $affordabilityCheck['shortage'] ?? null,
                ], 402); // 402 Payment Required
            }

            // Execute atomic transaction
            $transaction = DB::transaction(function () use ($user, $entryFee, $quizType, $quizId) {
                // Deduct entry fee from wallet
                $user->wallet->decrement('balance', $entryFee);

                // Create transaction record
                $walletTransaction = WalletTransaction::create([
                    'wallet_id' => $user->wallet->id,
                    'type' => 'quiz_entry_fee',
                    'amount' => -$entryFee,
                    'status' => 'completed',
                    'reference_id' => "{$quizType}_{$quizId}",
                ]);

                return $walletTransaction;
            });

            // Refresh wallet to get updated balance
            $user->wallet->refresh();

            Log::info('Quiz unlock transaction completed', [
                'user_id' => $user->id,
                'quiz_type' => $quizType,
                'quiz_id' => $quizId,
                'entry_fee' => $entryFee,
                'new_balance' => $user->wallet->balance,
                'transaction_id' => $transaction->id,
            ]);

            return response()->json([
                'status' => 'success',
                'message' => 'Quiz unlocked successfully',
                'transaction_id' => $transaction->id,
                'balance' => (float) $user->wallet->balance,
                'entry_fee' => $entryFee,
            ]);
        } catch (ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Validation failed',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            Log::error('Quiz unlock transaction failed', [
                'user_id' => $user->id ?? null,
                'exception' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'An error occurred while processing your request',
            ], 500);
        }
    }

    /**
     * POST /api/quiz/reward
     * Add reward points to user wallet and record the transaction.
     * Records the quiz attempt and blocks duplicate rewards.
     * Uses database transaction for atomicity.
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function reward(Request $request)
    {
        try {
            $user = $request->user();

            if (!$user) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Unauthenticated',
                ], 401);
            }

            // Validate request
            $validated = $request->validate([
                'quiz_type' => ['required', 'string', 'in:multiple_choice,true_false,daily_challenge,find_the_bug,live_duel'],
                'quiz_id' => ['required', 'integer'],
                'reward_points' => ['nullable', 'numeric', 'min:0'],
                'user_answer' => ['nullable', 'string'],
                'is_correct' => ['nullable', 'boolean'],
            ]);

            $quizType = $validated['quiz_type'];
            $quizId = $validated['quiz_id'];
            $customRewardPoints = isset($validated['reward_points']) ? (float) $validated['reward_points'] : null;
            $userAnswer = $validated['user_answer'] ?? null;
            $isCorrect = $validated['is_correct'] ?? false;

            // Check for duplicate attempt - BLOCK if already attempted
            if (UserQuizAttempt::hasUserAttempted($user->id, $quizType, $quizId)) {
                Log::warning('Duplicate quiz attempt prevented', [
                    'user_id' => $user->id,
                    'quiz_type' => $quizType,
                    'quiz_id' => $quizId,
                ]);

                $existingAttempt = UserQuizAttempt::getUserAttempt($user->id, $quizType, $quizId);

                return response()->json([
                    'status' => 'error',
                    'message' => 'You have already attempted this quiz',
                    'existing_attempt' => [
                        'is_correct' => $existingAttempt->is_correct,
                        'attempted_at' => $existingAttempt->created_at,
                    ],
                ], 409); // 409 Conflict
            }

            // Get the quiz access rules
            $rules = $this->quizAccessService->getAccessRules($quizType);
            if (!$rules) {
                return response()->json([
                    'status' => 'error',
                    'message' => "Invalid quiz type: {$quizType}",
                ], 400);
            }

            // Use custom reward points if provided, otherwise use default from rules
            $rewardPoints = $customRewardPoints ?? (float) $rules['reward_points'];

            // If no reward, still record attempt but don't add points
            if ($rewardPoints === 0.0) {
                DB::transaction(function () use ($user, $quizType, $quizId, $userAnswer, $isCorrect) {
                    UserQuizAttempt::create([
                        'user_id' => $user->id,
                        'quiz_type' => $quizType,
                        'quiz_id' => $quizId,
                        'user_answer' => $userAnswer,
                        'is_correct' => $isCorrect,
                    ]);
                });

                return response()->json([
                    'status' => 'success',
                    'message' => 'Attempt recorded (no reward for this quiz)',
                    'balance' => (float) $user->wallet->balance,
                    'reward_points' => $rewardPoints,
                ]);
            }

            // Ensure user has a wallet
            if (!$user->wallet) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'User wallet not found',
                ], 500);
            }

            // Execute atomic transaction - record attempt AND award reward
            $transaction = DB::transaction(function () use ($user, $rewardPoints, $quizType, $quizId, $userAnswer, $isCorrect) {
                // Record the quiz attempt (blocks duplicates at DB level with unique constraint)
                UserQuizAttempt::create([
                    'user_id' => $user->id,
                    'quiz_type' => $quizType,
                    'quiz_id' => $quizId,
                    'user_answer' => $userAnswer,
                    'is_correct' => $isCorrect,
                ]);

                // Add reward points to wallet
                $user->wallet->increment('balance', $rewardPoints);

                // Create transaction record
                $walletTransaction = WalletTransaction::create([
                    'wallet_id' => $user->wallet->id,
                    'type' => 'quiz_reward',
                    'amount' => $rewardPoints,
                    'status' => 'completed',
                    'reference_id' => "{$quizType}_{$quizId}",
                ]);

                return $walletTransaction;
            });

            // Refresh wallet to get updated balance
            $user->wallet->refresh();

            Log::info('Quiz reward transaction completed', [
                'user_id' => $user->id,
                'quiz_type' => $quizType,
                'quiz_id' => $quizId,
                'reward_points' => $rewardPoints,
                'new_balance' => $user->wallet->balance,
                'transaction_id' => $transaction->id,
                'is_correct' => $isCorrect,
            ]);

            return response()->json([
                'status' => 'success',
                'message' => 'Reward points awarded successfully',
                'transaction_id' => $transaction->id,
                'balance' => (float) $user->wallet->balance,
                'reward_points' => $rewardPoints,
            ]);
        } catch (ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Validation failed',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            Log::error('Quiz reward transaction failed', [
                'user_id' => $user->id ?? null,
                'exception' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'An error occurred while processing your request',
            ], 500);
        }
    }
}
