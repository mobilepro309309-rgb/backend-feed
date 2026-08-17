<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SubjectUnit extends Model
{
    use HasFactory;

    protected $table = 'subject_units';

    protected $fillable = [
        'school_grade',
        'subject',
        'total_units',
    ];

    protected $casts = [
        'total_units' => 'integer',
    ];
}
