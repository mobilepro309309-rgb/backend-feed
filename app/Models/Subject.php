<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Subject extends Model
{
    use HasFactory;

    protected $fillable = [
        'grade_id',
        'track_id',
        'code',
        'name_ar',
        'name_en',
        'education_type',
        'is_active',
    ];

    protected $casts = [
        'grade_id' => 'integer',
        'track_id' => 'integer',
        'is_active' => 'boolean',
    ];

    public function grade(): BelongsTo
    {
        return $this->belongsTo(Grade::class);
    }

    public function track(): BelongsTo
    {
        return $this->belongsTo(Track::class);
    }
}