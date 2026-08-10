<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Department extends Model
{
    protected $fillable = [
        'department_name',
        'department_code',
        'description',
    ];

    public function offices(): HasMany
    {
        return $this->hasMany(Office::class);
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }
}