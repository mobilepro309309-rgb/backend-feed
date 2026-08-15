<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BannedDevice extends Model
{
    protected $fillable = [
        'device_identifier',
        'user_id',
        'reason',
        'banned_by',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function bannedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'banned_by');
    }

    public static function isDeviceBanned(?string $deviceIdentifierOrToken): bool
    {
        if (!$deviceIdentifierOrToken) {
            return false;
        }

        $value = trim((string) $deviceIdentifierOrToken);
        if ($value === '') {
            return false;
        }

        return self::where('device_identifier', $value)->exists();
    }

    public static function getDeviceBanReason(?string $deviceIdentifierOrToken): ?string
    {
        if (!$deviceIdentifierOrToken) {
            return null;
        }

        $value = trim((string) $deviceIdentifierOrToken);
        if ($value === '') {
            return null;
        }

        $ban = self::where('device_identifier', $value)->first();
        return $ban?->reason;
    }

    public static function isUserBanned(int $userId): bool
    {
        return self::where('user_id', $userId)->exists();
    }

    public static function getUserBanReason(int $userId): ?string
    {
        $ban = self::where('user_id', $userId)->first();
        return $ban?->reason;
    }
}
