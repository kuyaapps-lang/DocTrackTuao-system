<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DocumentStatus extends Model
{
    protected $fillable = [
        'status_name',
        'color',
        'processing_action_id',
    ];

    public function documents(): HasMany
    {
        return $this->hasMany(Document::class);
    }
}
