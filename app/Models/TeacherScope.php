<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Builder;

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

    public function scopeForGradeAndSubject(Builder $query, mixed $grade, mixed $subject, bool $onlyAnswerable = true): Builder
    {
        $normalizedGrade = static::normalizeGradeValue($grade);
        $normalizedSubject = trim((string) $subject);

        $query = $query->where('subject', $normalizedSubject);

        if ($normalizedGrade !== null) {
            $query->where('school_grade', $normalizedGrade);
        }

        if ($onlyAnswerable) {
            $query->where('can_answer', true);
        }

        return $query;
    }

    public static function normalizeGradeValue(mixed $value): ?string
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }

        $raw = trim((string) $value);
        $clean = strtolower(str_replace([' ', 'ـ', '-'], '', $raw));

        $map = [
            'اولى' => '1',
            'اولي' => '1',
            'اول' => '1',
            'تانيه' => '2',
            'ثانية' => '2',
            'ثاني' => '2',
            'تالته' => '3',
            'ثالثة' => '3',
            'ثالث' => '3',
            '1' => '1',
            '2' => '2',
            '3' => '3',
        ];

        if (isset($map[$clean])) {
            return $map[$clean];
        }

        if (preg_match('/\d/', $raw)) {
            return (string) preg_replace('/\D+/', '', $raw);
        }

        return $raw;
    }
}
