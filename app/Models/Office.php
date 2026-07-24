<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Office extends Model
{
    protected $fillable = [
        'department_id',
        'office_name',
        'office_code',
        'description',
        'is_active'
    ];

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function incomingRoutes(): HasMany
    {
        return $this->hasMany(DocumentRoute::class, 'to_office_id');
    }

    public function outgoingRoutes(): HasMany
    {
        return $this->hasMany(DocumentRoute::class, 'from_office_id');
    }
}