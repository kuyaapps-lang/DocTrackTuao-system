<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Document extends Model
{
    protected $fillable = [
        'tracking_no',
        'title',
        'description',
        'status',
    ];
}