<?php

namespace App\Models\Challenges;

use App\Models\Challenges\LiveDuelChallenge;
use App\Models\Challenges\DuelParticipant;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DuelRoom extends Model
{
    use HasFactory;

    protected $table = 'duel_rooms';

    protected $fillable = [
        'challenge_id',
        'creator_id',
        'opponent_id',
        'status',
        'winner_id',
        'started_at',
        'ended_at',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'ended_at' => 'datetime',
    ];

    public function challenge(): BelongsTo
    {
        return $this->belongsTo(LiveDuelChallenge::class, 'challenge_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'creator_id');
    }

    public function opponent(): BelongsTo
    {
        return $this->belongsTo(User::class, 'opponent_id');
    }

    public function winner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'winner_id');
    }

    public function participants(): HasMany
    {
        return $this->hasMany(DuelParticipant::class, 'room_id');
    }
}
