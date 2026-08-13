<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Log;

use App\Http\Resources\Challenges\{CloudCapsuleChallengeResource, ComparisonChallengeResource, DailyChallengeResource, FindTheBugChallengeResource, LiveDuelChallengeResource};
use App\Http\Resources\Posts\PostResource;
use App\Http\Resources\Questions\{MultipleChoiceQuestionResource as McqQuestionResource, TrueFalseQuestionResource};
use App\Services\QuizAccessService;
use App\Models\UserQuizAttempt;

class FeedResource extends JsonResource
{
    public function toArray($request): array
    {
        $feedable = $this->feedable;
        if ($feedable) {
            $feedable->loadMissing('user');
        }

        $details = $feedable ? match (get_class($feedable)) {
            \App\Models\Posts\Post::class => new PostResource($feedable),
            \App\Models\Questions\MultipleChoiceQuestion::class => new McqQuestionResource($feedable),
            \App\Models\Questions\TrueFalseQuestion::class => new TrueFalseQuestionResource($feedable),
            \App\Models\Challenges\CloudCapsuleChallenge::class => new CloudCapsuleChallengeResource($feedable),
            \App\Models\Challenges\LiveDuelChallenge::class => new LiveDuelChallengeResource($feedable),
            \App\Models\Challenges\ComparisonChallenge::class => new ComparisonChallengeResource($feedable),
            \App\Models\Challenges\FindTheBugChallenge::class => new FindTheBugChallengeResource($feedable),
            \App\Models\Challenges\DailyChallenge::class => new DailyChallengeResource($feedable),
            default => null,
        } : null;

        if (is_array($details) && $feedable && $feedable->relationLoaded('user') && $feedable->user) {
            $details['user'] = [
                'id' => $feedable->user->id ?? null,
                'name' => $feedable->user->name ?? null,
                'avatar' => $feedable->user->avatar_url ?? $feedable->user->profile_image ?? $feedable->user->avatar ?? null,
                'role' => $feedable->user->role ?? null,
                'gender' => $feedable->user->gender ?? null,
                'school_grade' => $feedable->user->school_grade ?? null,
                'grade' => $feedable->user->school_grade ?? null,
            ];
        }

        $type = $feedable ? match (get_class($feedable)) {
            \App\Models\Posts\Post::class => 'post',
            \App\Models\Questions\MultipleChoiceQuestion::class => 'multiple-choice-question',
            \App\Models\Questions\TrueFalseQuestion::class => 'true-false-question',
            \App\Models\Challenges\CloudCapsuleChallenge::class => 'cloud-capsule-challenge',
            \App\Models\Challenges\LiveDuelChallenge::class => 'live-duel-challenge',
            \App\Models\Challenges\ComparisonChallenge::class => 'comparison-challenge',
            \App\Models\Challenges\FindTheBugChallenge::class => 'find-the-bug-challenge',
            \App\Models\Challenges\DailyChallenge::class => 'daily-challenge',
            default => 'unknown',
        } : null;

        $accessRules = null;
        if ($feedable && $type && in_array($type, ['cloud-capsule-challenge', 'live-duel-challenge', 'comparison-challenge', 'find-the-bug-challenge', 'daily-challenge', 'multiple-choice-question', 'true-false-question'], true)) {
            $quizType = UserQuizAttempt::normalizeQuizType($type);

            if ($quizType) {
                $accessRules = app(QuizAccessService::class)->buildAccessRulesObject($quizType, $request->user());
            }
        }

        // Check for user submission (ONLY for quiz types, EXCLUDING comparison_card and cloud_capsule)
        $userSubmission = null;
        if ($feedable && $type && in_array($type, ['find-the-bug-challenge', 'daily-challenge', 'live-duel-challenge', 'multiple-choice-question', 'true-false-question'], true)) {
            $quizType = UserQuizAttempt::normalizeQuizType($type);

            if ($quizType) {
                $userId = (int) ($request->user()?->id ?? auth('sanctum')->id() ?? auth()->id() ?? 0);
                $feedItemId = (int) ($this->id ?? 0);
                $detailsId = null;

                if (is_array($details) && isset($details['id'])) {
                    $detailsId = (int) $details['id'];
                } elseif (is_object($details) && isset($details->id)) {
                    $detailsId = (int) $details->id;
                }

                $feedableId = (int) ($feedable->id ?? 0);
                $candidateQuizIds = array_values(array_unique(array_filter([
                    $feedItemId,
                    $detailsId,
                    $feedableId,
                ], fn ($id) => $id > 0)));

                $attempt = null;
                $resolvedQuizId = $candidateQuizIds[0] ?? null;

                foreach ($candidateQuizIds as $candidateQuizId) {
                    $candidateAttempt = UserQuizAttempt::getUserAttempt($userId, $quizType, $candidateQuizId);
                    if ($candidateAttempt) {
                        $attempt = $candidateAttempt;
                        $resolvedQuizId = $candidateQuizId;
                        break;
                    }
                }

                if ($attempt) {
                    $userSubmission = [
                        'has_answered' => true,
                        'user_answer' => $attempt->user_answer,
                        'is_correct' => (bool) $attempt->is_correct,
                    ];
                } else {
                    $userSubmission = [
                        'has_answered' => false,
                        'user_answer' => null,
                        'is_correct' => false,
                    ];
                }

                Log::info("CHECK ATTEMPT: User {$userId} | Type {$quizType} | ID {$resolvedQuizId} => Found: " . ($attempt ? 'YES' : 'NO'));
                Log::info('[FeedResource] quiz submission lookup', [
                    'user_id' => $userId,
                    'quiz_type' => $quizType,
                    'candidate_quiz_ids' => $candidateQuizIds,
                    'resolved_quiz_id' => $resolvedQuizId,
                    'user_submission' => $userSubmission,
                ]);
            }
        }

        return [
            'id' => $this->id,
            'type' => $type,
            'is_pinned' => (bool) $this->is_pinned,
            'status' => $this->status,
            'created_at' => $this->created_at?->toIso8601String(),
            'details' => $details,
            'access_rules' => $accessRules,
            'user_submission' => $userSubmission,
        ];
    }
}
