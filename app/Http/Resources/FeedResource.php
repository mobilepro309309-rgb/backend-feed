<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

use App\Http\Resources\Challenges\{CloudCapsuleChallengeResource, ComparisonChallengeResource, DailyChallengeResource, FindTheBugChallengeResource, LiveDuelChallengeResource};
use App\Http\Resources\Posts\PostResource;
use App\Http\Resources\Questions\{MultipleChoiceQuestionResource as McqQuestionResource, TrueFalseQuestionResource};

class FeedResource extends JsonResource
{
    public function toArray($request): array
    {
        $feedable = $this->feedable;

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

        return [
            'id' => $this->id,
            'type' => $type,
            'is_pinned' => (bool) $this->is_pinned,
            'status' => $this->status,
            'created_at' => $this->created_at?->toIso8601String(),
            'details' => $details,
        ];
    }
}
