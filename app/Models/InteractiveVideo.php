<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class InteractiveVideo extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'title',
        'youtube_url',
        'subject',
        'school_grade',
        'term',
        'unit_number',
        'number_of_questions',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function videoQuestions()
    {
        return $this->hasMany(VideoQuestion::class);
    }
}
