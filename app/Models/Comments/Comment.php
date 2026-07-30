<?php

declare(strict_types=1);

namespace App\Models\Comments;

use App\Models\Posts\Post;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Comment extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $table = 'post_comments';

    protected $fillable = [
        'post_id',
        'user_id',
        'parent_id',
        'content',
        'type',
        'metadata',
        'file_url',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
            'deleted_at' => 'datetime',
        ];
    }

    /**
     * Relationship: This comment belongs to a post
     */
    public function post(): BelongsTo
    {
        return $this->belongsTo(Post::class, 'post_id');
    }

    /**
     * Relationship: This comment was authored by a user
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * Relationship: Get the parent comment (if this is a reply)
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(Comment::class, 'parent_id');
    }

    /**
     * Relationship: Get all direct replies to this comment (nested sub-comments)
     * Supports unlimited depth for recursive comment threads
     */
    public function replies(): HasMany
    {
        return $this->hasMany(Comment::class, 'parent_id', 'id')->orderByDesc('created_at');
    }

    /**
     * Relationship: Get all reactions on this comment
     */
    public function reactions(): HasMany
    {
        return $this->hasMany(CommentReaction::class, 'comment_id');
    }

    /**
     * Relationship: Get all attachments on this comment
     */
    public function attachments(): HasMany
    {
        return $this->hasMany(CommentAttachment::class, 'comment_id');
    }

    /**
     * Get all descendants of this comment (recursively counts all nested replies)
     */
    public function allDescendants(): int
    {
        $count = $this->replies()->count();
        foreach ($this->replies as $reply) {
            $count += $reply->allDescendants();
        }
        return $count;
    }

    /**
     * Load all nested replies recursively (load entire comment tree)
     */
    public function loadAllReplies(): void
    {
        $this->load('replies');
        foreach ($this->replies as $reply) {
            $reply->loadAllReplies();
        }
    }
}
