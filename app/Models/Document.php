<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Document extends Model
{
    protected $fillable = [
        'tracking_no',
        'title',
        'description',

        'document_type_id',
        'status_id',
        'priority_id',
        'confidentiality_level_id',

        'origin_office_id',
        'current_office_id',

        'created_by',

        'document_date',
        'due_date',
    ];

    /*
    |--------------------------------------------------------------------------
    | Lookup Tables
    |--------------------------------------------------------------------------
    */

    public function type(): BelongsTo
    {
        return $this->belongsTo(DocumentType::class, 'document_type_id');
    }

    public function status(): BelongsTo
    {
        return $this->belongsTo(DocumentStatus::class);
    }

    public function priority(): BelongsTo
    {
        return $this->belongsTo(Priority::class);
    }

    public function confidentiality(): BelongsTo
    {
        return $this->belongsTo(
            ConfidentialityLevel::class,
            'confidentiality_level_id'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Offices
    |--------------------------------------------------------------------------
    */

    public function originOffice(): BelongsTo
    {
        return $this->belongsTo(
            Office::class,
            'origin_office_id'
        );
    }

    public function currentOffice(): BelongsTo
    {
        return $this->belongsTo(
            Office::class,
            'current_office_id'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Users
    |--------------------------------------------------------------------------
    */

    public function creator(): BelongsTo
    {
        return $this->belongsTo(
            User::class,
            'created_by'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Child Tables
    |--------------------------------------------------------------------------
    */

    public function routes(): HasMany
    {
        return $this->hasMany(DocumentRoute::class);
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(DocumentAttachment::class);
    }

    public function comments(): HasMany
    {
        return $this->hasMany(DocumentComment::class);
    }
}