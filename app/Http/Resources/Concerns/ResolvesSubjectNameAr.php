<?php

declare(strict_types=1);

namespace App\Http\Resources\Concerns;

use App\Models\Subject;

trait ResolvesSubjectNameAr
{
    private function resolveSubjectNameAr(): ?string
    {
        $subjectValue = ltrim(trim((string) ($this->subject ?? '')), '#');

        if ($subjectValue === '') {
            return null;
        }

        $subject = ctype_digit($subjectValue)
            ? Subject::query()->find((int) $subjectValue)
            : Subject::query()->where('code', $subjectValue)->first();

        if ($subject?->name_ar) {
            return $subject->name_ar;
        }

        return preg_match('/[\x{0600}-\x{06FF}]/u', $subjectValue) ? $subjectValue : null;
    }
}
