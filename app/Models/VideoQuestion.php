<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class VideoQuestion extends Model
{
    use HasFactory;

    protected $guarded = [];

    public function interactiveVideo()
    {
        return $this->belongsTo(InteractiveVideo::class);
    }
}
