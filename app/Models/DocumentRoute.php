<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Schema;

class DocumentRoute extends Model
{
    protected $fillable = [
        'document_id',
        'from_office_id',
        'to_office_id',
        'forwarded_by',
        'received_by',
        'forwarded_at',
        'received_at',
        'cancelled_by',
        'cancelled_at',
        'cancellation_reason',
        'status_id',
        'remarks',
        'action_id',
    ];

    /*
    |--------------------------------------------------------------------------
    | Casts
    |--------------------------------------------------------------------------
    */

    protected function casts(): array
    {
        return [
            'forwarded_at' =>
                'datetime',

            'received_at' =>
                'datetime',

            'cancelled_at' =>
                'datetime',
        ];
    }

    /**
     * Routes awaiting receipt. The column check keeps older test fixtures and
     * pre-migration installations readable while the migration is deployed.
     */
    public function scopePending(Builder $query): Builder
    {
        $query->whereNull('received_at');

        if (Schema::hasColumn($this->getTable(), 'cancelled_at')) {
            $query->whereNull('cancelled_at');
        }

        return $query;
    }

    /*
    |--------------------------------------------------------------------------
    | Document
    |--------------------------------------------------------------------------
    */

    public function document(): BelongsTo
    {
        return $this->belongsTo(
            Document::class
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Offices
    |--------------------------------------------------------------------------
    */

    public function fromOffice(): BelongsTo
    {
        return $this->belongsTo(
            Office::class,
            'from_office_id'
        );
    }

    public function toOffice(): BelongsTo
    {
        return $this->belongsTo(
            Office::class,
            'to_office_id'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Users
    |--------------------------------------------------------------------------
    */

    public function forwardedBy(): BelongsTo
    {
        return $this->belongsTo(
            User::class,
            'forwarded_by'
        );
    }

    public function receivedBy(): BelongsTo
    {
        return $this->belongsTo(
            User::class,
            'received_by'
        );
    }

    public function cancelledBy(): BelongsTo
    {
        return $this->belongsTo(
            User::class,
            'cancelled_by'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Status
    |--------------------------------------------------------------------------
    */

    public function status(): BelongsTo
    {
        return $this->belongsTo(
            DocumentStatus::class,
            'status_id'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Route Action
    |--------------------------------------------------------------------------
    */

    public function action(): BelongsTo
    {
        return $this->belongsTo(
            RouteAction::class,
            'action_id'
        );
    }
}
