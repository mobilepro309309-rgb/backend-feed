<?php

declare(strict_types=1);

namespace App\Http\Resources\Challenges;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use App\Http\Resources\Concerns\ResolvesSubjectNameAr;

class CloudCapsuleChallengeResource extends JsonResource
{
    use ResolvesSubjectNameAr;

    public function toArray($request): array
    {
        $quizAccessService = app(\App\Services\QuizAccessService::class);

        return [
            'id' => $this->id,
            'user_id' => $this->user_id,
            'file_url' => $this->file_url ?? null,
            'explanation' => $this->getRawOriginal('explanation'),
            'title' => $this->title,
            'subject' => $this->subject,
            'subject_name_ar' => $this->resolveSubjectNameAr(),
            'intro_text' => $this->intro_text,
            'badge_text' => $this->badge_text,
            'reveal_text' => $this->reveal_text,
            'tip_text' => $this->tip_text,
            'mood_text' => $this->mood_text,
            'reveal_label' => $this->reveal_label,
            'icon' => $this->icon,
            'status' => $this->status,
            'published_at' => $this->published_at,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'access_rules' => $quizAccessService->buildAccessRulesObject('cloud_capsule', $request->user()),
        ];
    }
}
