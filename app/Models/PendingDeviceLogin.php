<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PendingDeviceLogin extends Model
{
    protected $fillable = [
        'user_id',
        'target_device_id',
        'status',
        'reason',
        'auth_token',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function targetDevice(): BelongsTo
    {
        return $this->belongsTo(UserDevice::class, 'target_device_id');
    }
}
