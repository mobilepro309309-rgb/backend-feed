<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SpecializedSubject extends Model
{
    use HasFactory;

    protected $fillable = [
        'track_id',
        'code',
        'name_ar',
        'name_en',
        'is_mandatory',
        'sort_order',
        'is_active',
    ];

    protected $casts = [
        'track_id' => 'integer',
        'is_mandatory' => 'boolean',
        'sort_order' => 'integer',
        'is_active' => 'boolean',
    ];

    public function track(): BelongsTo
    {
        return $this->belongsTo(Track::class);
    }
}