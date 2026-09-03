<?php

declare(strict_types=1);

namespace App\Http\Resources\Challenges;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use App\Http\Resources\Concerns\ResolvesSubjectNameAr;

class ChallengeResource extends JsonResource
{
    use ResolvesSubjectNameAr;

    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'type' => class_basename($this->resource),
            'user_id' => $this->user_id,
            'title' => $this->title,
            'subject' => $this->subject,
            'subject_name_ar' => $this->resolveSubjectNameAr(),
            'explanation' => $this->getRawOriginal('explanation'),
            'badge_text' => $this->badge_text,
            'status' => $this->status,
            'published_at' => $this->published_at,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
