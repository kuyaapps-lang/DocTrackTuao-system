<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AuditLog extends Model
{
    public const MODULE_AUTHENTICATION = 'authentication';
    public const MODULE_DOCUMENTS = 'documents';
    public const MODULE_DOCUMENT_ROUTING = 'document_routing';
    public const MODULE_DOCUMENT_PROCESSING = 'document_processing';

    public const ACTION_LOGIN = 'login';
    public const ACTION_LOGOUT = 'logout';
    public const ACTION_CREATED = 'created';
    public const ACTION_UPDATED = 'updated';
    public const ACTION_DELETED = 'deleted';
    public const ACTION_FORWARDED = 'forwarded';
    public const ACTION_RECEIVED = 'received';
    public const ACTION_PROCESSING_UPDATED = 'processing_updated';

    protected $fillable = [
        'user_id',
        'module',
        'action',
        'record_id',
        'description',
        'ip_address',
        'user_agent',
    ];

    protected $casts = [
        'user_id' => 'integer',
        'record_id' => 'integer',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
