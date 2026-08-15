<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ShareRewardLog extends Model
{
    use HasFactory;

    protected $table = 'share_reward_logs';

    protected $fillable = [
        'user_id',
        'platform',
        'share_day',
        'points_awarded',
    ];

    protected $casts = [
        'points_awarded' => 'integer',
        'share_day' => 'date',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $log): void {
            if (empty($log->share_day)) {
                $log->share_day = now()->toDateString();
            }
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
