<?php

declare(strict_types=1);

namespace App\Models\Posts;

use App\Models\Comments\Comment;
use App\Models\Feed;
use App\Models\User;
use App\Traits\SyncsToFeed;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Post extends Model
{
    use HasFactory;
    use SyncsToFeed;

    protected static function booted(): void
    {
        static::deleting(function (self $item): void {
            Feed::where('feedable_type', get_class($item))
                ->where('feedable_id', $item->id)
                ->delete();
        });
    }

    protected $fillable = [
        'user_id',
        'content',
        'subject',
        'image_url',
        'attachments',
        'status',
        'published_at',
    ];

    protected $appends = ['likes', 'comments', 'shares'];

    protected function casts(): array
    {
        return [
            'attachments' => 'array',
            'published_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function feeds(): MorphMany
    {
        return $this->morphMany(Feed::class, 'feedable');
    }

    public function reactions(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'post_reactions', 'post_id', 'user_id')
            ->withPivot('type')
            ->withTimestamps();
    }

    /**
     * Relationship: Get all top-level comments on this post (parent_id is null)
     * Use eager-loading to fetch nested replies recursively
     */
    public function comments(): HasMany
    {
        return $this->hasMany(Comment::class, 'post_id')
            ->whereNull('parent_id')
            ->orderByDesc('created_at');
    }

    /**
     * Relationship: Get ALL comments including nested replies
     */
    public function allComments(): HasMany
    {
        return $this->hasMany(Comment::class, 'post_id')
            ->orderByDesc('created_at');
    }

    public function getLikesAttribute(): int
    {
        return (int) $this->reactions()->count();
    }

    public function getCommentsAttribute(): int
    {
        return (int) $this->allComments()->count();
    }

    public function getSharesAttribute(): int
    {
        return 0; // TODO: Implement shares table later
    }
}
