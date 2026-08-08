<?php

namespace App\Models\Challenges;

use App\Models\Challenges\DuelRoom;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DuelParticipant extends Model
{
    use HasFactory;

    protected $table = 'duel_participants';

    protected $fillable = [
        'room_id',
        'user_id',
        'score',
        'status',
    ];

    public function room(): BelongsTo
    {
        return $this->belongsTo(DuelRoom::class, 'room_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
