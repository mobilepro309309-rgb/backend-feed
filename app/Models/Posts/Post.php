<?php

declare(strict_types=1);

namespace App\Models\Posts;

use App\Models\Comments\Comment;
use App\Models\Feed;
use App\Models\PostVote;
use App\Models\User;
use App\Services\FeedCacheService;
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

        static::saved(function (self $item): void {
            app(FeedCacheService::class)->flush();
        });
        static::deleted(function (self $item): void {
            app(FeedCacheService::class)->flush();
        });
    }

    protected $fillable = [
        'user_id',
        'content',
        'subject',
        'unit_number',
        'image_url',
        'attachments',
        'status',
        'published_at',
        'votes_score',
        'upvotes_count',
        'downvotes_count',
    ];

    protected $attributes = [
        'status' => 'pending',
    ];

    protected $appends = ['shares'];

    protected function casts(): array
    {
        return [
            'attachments' => 'array',
            'published_at' => 'datetime',
            'unit_number' => 'integer',
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

    public function votes(): HasMany
    {
        return $this->hasMany(PostVote::class, 'post_id');
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

    public function scopeForGradeFilter($query, mixed $gradeValue)
    {
        $normalizedGrade = $gradeValue === null || $gradeValue === ''
            ? null
            : User::normalizeSchoolGradeValue((string) $gradeValue);

        if ($normalizedGrade === null || $normalizedGrade === '') {
            return $query;
        }

        return $query->whereHas('user', function ($userQuery) use ($normalizedGrade) {
            // عايير الـ school_grade من الـ database أيضاً عشان نقارن
            $userQuery->whereRaw("
                CASE 
                    WHEN school_grade IS NULL THEN ''
                    WHEN LOWER(school_grade) IN ('اول', 'اولى', 'اولي', 'اولى اعدادي', 'اول اعدادي', '1') THEN '1'
                    WHEN LOWER(school_grade) IN ('ثاني', 'ثانية', 'ثانى', 'ثانيه', 'ثاني اعدادي', 'ثانى اعدادي', '2') THEN '2'
                    WHEN LOWER(school_grade) IN ('ثالث', 'ثالثة', 'ثالثه', 'ثالث اعدادي', 'ثالثة اعدادي', '3') THEN '3'
                    WHEN LOWER(school_grade) IN ('رابع', 'رابعة', 'رابع ثانوي', 'اول ثانوي', '4') THEN '4'
                    WHEN LOWER(school_grade) IN ('خامس', 'خامسة', 'ثاني ثانوي', '5') THEN '5'
                    WHEN LOWER(school_grade) IN ('سادس', 'سادسة', 'ثالث ثانوي', '6') THEN '6'
                    WHEN LOWER(school_grade) IN ('سابع', 'سابعة', '7') THEN '7'
                    WHEN LOWER(school_grade) IN ('ثامن', 'ثامنة', '8') THEN '8'
                    WHEN LOWER(school_grade) IN ('تاسع', 'تاسعة', '9') THEN '9'
                    WHEN LOWER(school_grade) IN ('عاشر', 'عاشرة', '10') THEN '10'
                    WHEN LOWER(school_grade) IN ('حادي عشر', 'حادية عشرة', '11') THEN '11'
                    WHEN LOWER(school_grade) IN ('ثاني عشر', 'ثانية عشرة', '12') THEN '12'
                    ELSE LOWER(school_grade)
                END = ?
            ", [$normalizedGrade]);
        });
    }
}
