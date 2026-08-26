<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

use App\Http\Resources\Challenges\{CloudCapsuleChallengeResource, ComparisonChallengeResource, DailyChallengeResource, FindTheBugChallengeResource, LiveDuelChallengeResource};
use App\Http\Resources\Posts\PostResource;
use App\Http\Resources\Questions\{MultipleChoiceQuestionResource as McqQuestionResource, TrueFalseQuestionResource};
use App\Services\QuizAccessService;
use App\Models\UserQuizAttempt;

class FeedResource extends JsonResource
{
    private function buildQuestionTypeCandidates($feedable): array
    {
        if (! $feedable || ! is_object($feedable)) {
            return [];
        }

        $className = $feedable::class;
        $candidates = [];

        foreach ([
            $className,
            ltrim($className, '\\'),
            Str::after($className, '\\'),
            Str::afterLast($className, '\\'),
            class_basename($className),
        ] as $candidate) {
            if (is_string($candidate) && trim($candidate) !== '') {
                $candidates[] = trim($candidate);
            }
        }

        foreach (Relation::morphMap() as $alias => $mappedClass) {
            if (! is_string($alias) || ! is_string($mappedClass)) {
                continue;
            }

            $aliasName = trim($alias);
            $mappedClassName = trim($mappedClass);

            if ($mappedClassName === '' || $aliasName === '') {
                continue;
            }

            if (
                $mappedClassName === $className
                || ltrim($mappedClassName, '\\') === ltrim($className, '\\')
                || class_basename($mappedClassName) === class_basename($className)
                || $aliasName === class_basename($className)
                || $aliasName === Str::afterLast($className, '\\')
            ) {
                $candidates[] = $aliasName;
                $candidates[] = $mappedClassName;
                $candidates[] = ltrim($mappedClassName, '\\');
                $candidates[] = class_basename($mappedClassName);
            }
        }

        $normalized = [];
        foreach ($candidates as $candidate) {
            $sanitized = trim((string) $candidate);
            if ($sanitized === '') {
                continue;
            }

            $normalized[] = $sanitized;
            $normalized[] = ltrim($sanitized, '\\');
            $normalized[] = Str::after($sanitized, '\\');
            $normalized[] = Str::afterLast($sanitized, '\\');
            $normalized[] = class_basename($sanitized);
        }

        return array_values(array_unique(array_filter($normalized, fn ($value) => is_string($value) && $value !== '')));
    }

    private function resolveExplanationVideoUrl($feedable): ?string
    {
        if (! $feedable || ! method_exists($feedable, 'getKey')) {
            return null;
        }

        $questionId = $feedable->getKey();
        if ($questionId === null) {
            return null;
        }

        if (method_exists($feedable, 'explanation')) {
            $feedable->loadMissing('explanation');

            $directUrl = $feedable->explanation?->video_url ?? null;
            if (is_string($directUrl) && trim($directUrl) !== '') {
                return trim($directUrl);
            }
        }

        $candidateTypes = $this->buildQuestionTypeCandidates($feedable);

        $query = \App\Models\QuestionExplanation::query()
            ->where('question_id', $questionId);

        if (! empty($candidateTypes)) {
            $query->where(function ($whereQuery) use ($candidateTypes) {
                foreach ($candidateTypes as $candidateType) {
                    $whereQuery->orWhere('question_type', $candidateType);
                }
            });
        } else {
            $query->where('question_type', $feedable::class);
        }

        $explanation = $query->orderByDesc('id')->first();

        if ($explanation) {
            $videoUrl = $explanation->video_url ?? null;

            if (is_string($videoUrl) && trim($videoUrl) !== '') {
                return trim($videoUrl);
            }
        }

        if (method_exists($feedable, 'explanation')) {
            return $feedable->explanation?->video_url ?? null;
        }

        return null;
    }

    public function toArray($request): array
    {
        $feedable = $this->feedable;
        if ($feedable) {
            $feedable->loadMissing('user');

            if (method_exists($feedable, 'explanation')) {
                $feedable->loadMissing('explanation');
            }
        }

        $detailsResource = $feedable ? match (get_class($feedable)) {
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

        // Convert resource to array so we can inject explanation_video_url universally
        $details = null;
        if ($detailsResource) {
            $details = $detailsResource->toArray($request);
        }

        if (is_array($details) && $feedable) {
            $details['explanation'] = $feedable->getRawOriginal('explanation');
            $details['explanation_video_url'] = $this->resolveExplanationVideoUrl($feedable);

            if ($feedable->relationLoaded('user') && $feedable->user) {
                $details['user'] = [
                    'id' => $feedable->user->id ?? null,
                    'name' => $feedable->user->name ?? null,
                    'avatar' => $feedable->user->avatar_url ?? $feedable->user->profile_image ?? $feedable->user->avatar ?? null,
                    'role' => $feedable->user->role ?? null,
                    'gender' => $feedable->user->gender ?? null,
                    'school_grade' => $feedable->user->school_grade ?? null,
                    'grade' => $feedable->user->school_grade ?? null,
                    'stage_id' => $feedable->user->stage_id ?? null,
                    'grade_id' => $feedable->user->grade_id ?? null,
                    'track_id' => $feedable->user->track_id ?? null,
                    'specialized_subject_id' => $feedable->user->specialized_subject_id ?? null,
                    'education_system' => $feedable->user->education_system ?? 'general',
                    'stage' => $feedable->user->stage ?? null,
                    'educational_grade' => $feedable->user->grade ?? null,
                    'track' => $feedable->user->track ?? null,
                    'specialized_subject' => $feedable->user->specializedSubject ?? null,
                ];
            }
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
