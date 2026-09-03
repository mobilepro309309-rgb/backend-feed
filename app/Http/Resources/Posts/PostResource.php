<?php

declare(strict_types=1);

namespace App\Http\Resources\Posts;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use App\Http\Resources\Concerns\ResolvesSubjectNameAr;

class PostResource extends JsonResource
{
    use ResolvesSubjectNameAr;

    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'user_id' => $this->user_id,
            'subject' => $this->subject,
            'subject_name_ar' => $this->resolveSubjectNameAr(),
            'content' => $this->content,
            'image_url' => $this->image_url,
            'attachments' => $this->attachments,
            'status' => $this->status,
            'published_at' => $this->published_at,
            'likes' => (int) ($this->likes_count ?? 0),
            'comments' => (int) ($this->comments_count ?? 0),
            'comments_count' => (int) ($this->all_comments_count ?? $this->comments_count ?? 0),
            'all_comments_count' => (int) ($this->all_comments_count ?? $this->comments_count ?? 0),
            'shares' => $this->shares,
            'votes_score' => $this->votes_score ?? 0,
            'upvotes_count' => $this->upvotes_count ?? 0,
            'downvotes_count' => $this->downvotes_count ?? 0,
            'user_vote' => $this->whenLoaded('votes', function () {
                $vote = $this->votes->first();
                if (! $vote) {
                    return null;
                }

                return $vote->vote_type === 1 ? 'up' : ($vote->vote_type === -1 ? 'down' : null);
            }),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            // include the user when the relation was eager-loaded
            'user' => $this->whenLoaded('user', function () {
                $user = $this->user;
                if (! $user) {
                    return null;
                }

                return [
                    'id' => $user->id ?? null,
                    'name' => $user->name ?? null,
                    'avatar' => $user->avatar_url ?? $user->profile_image ?? $user->avatar ?? null,
                    'role' => $user->role ?? null,
                    'gender' => $user->gender ?? $user->profile?->gender ?? null,
                    'school_grade' => $user->school_grade ?? null,
                    'grade' => $user->school_grade ?? null,
                ];
            }),
        ];
    }
}
