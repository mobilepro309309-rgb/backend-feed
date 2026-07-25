<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserDevice extends Model
{
    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'fcm_token',
        'device_type',
    ];

    /**
     * Get the user that owns the device record.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
