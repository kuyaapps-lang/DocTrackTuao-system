<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DocumentProcessingLog extends Model
{
    protected $fillable = [
        'document_id',
        'office_id',
        'user_id',
        'processing_action_id',
        'document_route_id',
        'event_type',
        'processing_note',
        'event_note',
    ];

    public function document(): BelongsTo
    {
        return $this->belongsTo(
            Document::class
        );
    }

    public function office(): BelongsTo
    {
        return $this->belongsTo(
            Office::class
        );
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(
            User::class
        );
    }

    public function action(): BelongsTo
    {
        return $this->belongsTo(
            ProcessingAction::class,
            'processing_action_id'
        );
    }

    public function route(): BelongsTo
    {
        return $this->belongsTo(
            DocumentRoute::class,
            'document_route_id'
        );
    }
}