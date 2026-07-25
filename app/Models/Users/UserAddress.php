<?php

namespace App\Models\Users;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

class UserAddress extends Model
{
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'governorate',
        'city_or_center',
        'village_name',
        'latitude',
        'longitude',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'latitude' => 'float',
        'longitude' => 'float',
    ];

    /**
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function village(): BelongsTo
    {
        return $this->belongsTo(Village::class);
    }

    /**
     * Return nearby addresses within the supplied radius in kilometers.
     */
    public function scopeWithinRadius(Builder $query, float $latitude, float $longitude, float $radiusInKm = 2.0): Builder
    {
        $connection = $query->getModel()->getConnection()->getDriverName();

        if ($connection === 'sqlite') {
            $latDelta = $radiusInKm / 111.0;
            $lonDelta = $radiusInKm / (111.0 * cos(deg2rad($latitude)));

            return $query
                ->select('*')
                ->whereNotNull('latitude')
                ->whereNotNull('longitude')
                ->whereBetween('latitude', [$latitude - $latDelta, $latitude + $latDelta])
                ->whereBetween('longitude', [$longitude - $lonDelta, $longitude + $lonDelta])
                ->addSelect(DB::raw('0 AS distance_km'));
        }

        $earthRadiusKm = 6371.0;

        return $query
            ->selectRaw(
                sprintf(
                    '*, (%s * acos(cos(radians(?)) * cos(radians(latitude)) * cos(radians(longitude) - radians(?)) + sin(radians(?)) * sin(radians(latitude)))) AS distance_km',
                    $earthRadiusKm
                ),
                [$latitude, $longitude, $latitude]
            )
            ->whereNotNull('latitude')
            ->whereNotNull('longitude')
            ->whereRaw(
                sprintf(
                    '(%s * acos(cos(radians(?)) * cos(radians(latitude)) * cos(radians(longitude) - radians(?)) + sin(radians(?)) * sin(radians(latitude)))) <= ?',
                    $earthRadiusKm
                ),
                [$latitude, $longitude, $latitude, $radiusInKm]
            )
            ->orderBy('distance_km');
    }
}
