<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RouteAction extends Model
{
    protected $fillable = [
        'action_name',
        'description',
    ];

    /*
    |--------------------------------------------------------------------------
    | Document Routes
    |--------------------------------------------------------------------------
    */

    public function routes(): HasMany
    {
        return $this->hasMany(
            DocumentRoute::class,
            'action_id'
        );
    }
}