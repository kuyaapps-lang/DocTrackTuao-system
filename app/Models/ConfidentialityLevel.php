<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ConfidentialityLevel extends Model
{
    protected $fillable = [
        'level_name',
    ];

    public function documents(): HasMany
    {
        return $this->hasMany(Document::class);
    }
}