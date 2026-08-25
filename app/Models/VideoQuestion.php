<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class VideoQuestion extends Model
{
    use HasFactory;

    protected $fillable = [
        'interactive_video_id',
        'question_text',
        'choice_1',
        'choice_2',
        'choice_3',
        'choice_4',
        'correct_choice',
        'stop_minute',
        'stop_second',
        'file_url',
        'explanation',
        'difficulty',
    ];

    public function interactiveVideo()
    {
        return $this->belongsTo(InteractiveVideo::class);
    }
}
