<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class YouTubeVideo extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $table = 'youtube_videos';

    protected $fillable = [
        'user_id',
        'youtube_video_id',
        'title',
        'description',
        'status',
        'video_url',
        'meta_data',
    ];

    protected $casts = [
        'meta_data' => 'array',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
