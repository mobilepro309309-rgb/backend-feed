<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InteractiveVideo extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'subject_id',
        'stage_id',
        'grade_id',
        'track_id',
        'title',
        'youtube_url',
        'subject',
        'school_grade',
        'term',
        'unit_number',
        'number_of_questions',
        'difficulty',
    ];

    protected $casts = [
        'subject_id' => 'integer',
        'stage_id' => 'integer',
        'grade_id' => 'integer',
        'track_id' => 'integer',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class);
    }

    public function videoQuestions()
    {
        return $this->hasMany(VideoQuestion::class);
    }
}
