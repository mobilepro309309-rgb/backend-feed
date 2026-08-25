<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SubjectUnit extends Model
{
    use HasFactory;

    protected $table = 'subject_units';

    protected $fillable = [
        'subject_id',
        'total_units',
    ];

    protected $casts = [
        'subject_id' => 'integer',
        'total_units' => 'integer',
    ];

    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class);
    }
}
