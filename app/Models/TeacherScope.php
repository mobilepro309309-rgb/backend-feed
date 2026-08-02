<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TeacherScope extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'school_grade',
        'subject',
        'can_answer',
        'can_create_questions',
    ];

    protected $casts = [
        'can_answer' => 'boolean',
        'can_create_questions' => 'boolean',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
