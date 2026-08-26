<?php

declare(strict_types=1);

namespace App\Http\Resources\Challenges;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ComparisonChallengeResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'user_id' => $this->user_id,
            'file_url' => $this->file_url ?? null,
            'title' => $this->title,
            'subject' => $this->subject,
            'left_label' => $this->left_label,
            'right_label' => $this->right_label,
            'left_text' => $this->left_text,
            'right_text' => $this->right_text,
            'explanation' => $this->getRawOriginal('explanation'),
            'badge_text' => $this->badge_text,
            'status' => $this->status,
            'published_at' => $this->published_at,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
