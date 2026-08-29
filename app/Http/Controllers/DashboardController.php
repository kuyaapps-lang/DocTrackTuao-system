<?php

namespace App\Http\Controllers;

use App\Models\User;
use Carbon\CarbonImmutable;
use DateTimeZone;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

class DashboardController extends Controller
{
    private const RECENT_LIMIT = 10;
    private const UNAVAILABLE_MESSAGE =
        'Dashboard summary is temporarily unavailable.';

    public function summary(Request $request): JsonResponse
    {
        $this->rejectUnknownParameters($request);

        $validated = $request->validate([
            'month' => [
                'sometimes',
                'string',
                'regex:/\A(?!0000)\d{4}-(0[1-9]|1[0-2])\z/',
            ],
        ]);

        $reportingTimezone = $this->reportingTimezone();

        if ($reportingTimezone === null) {
            return response()->json([
                'message' => self::UNAVAILABLE_MESSAGE,
            ], 500);
        }

        $timezone = $reportingTimezone->getName();
        $bounds = $this->monthBounds(
            $validated['month'] ?? null,
            $reportingTimezone
        );
        $user = $request->user();
        $systemWide = $this->hasSystemScope($user);
        $office = $this->resolveOfficeScope($user, $systemWide);
        $officeId = $office ? (int) $office->id : null;

        $documents = $this->documentQuery($officeId, $bounds);

        $summary = [
            'total_documents' => (clone $documents)
                ->distinct()
                ->count('documents.id'),
            'incoming_movements' => $this->movementCount(
                'to_office_id',
                $officeId,
                $bounds
            ),
            'outgoing_movements' => $this->movementCount(
                'from_office_id',
                $officeId,
                $bounds
            ),
            'in_transit_documents' => (clone $documents)
                ->whereExists(function (Builder $query): void {
                    $query
                        ->selectRaw('1')
                        ->from('document_routes as pending_routes')
                        ->whereColumn(
                            'pending_routes.document_id',
                            'documents.id'
                        )
                        ->whereNull('pending_routes.received_at');
                })
                ->distinct()
                ->count('documents.id'),
            'received_documents' => (clone $documents)
                ->whereExists(function (Builder $query): void {
                    $query
                        ->selectRaw('1')
                        ->from('document_statuses as received_statuses')
                        ->whereColumn(
                            'received_statuses.id',
                            'documents.status_id'
                        )
                        ->where(
                            'received_statuses.status_name',
                            'Received'
                        );
                })
                ->whereNotExists(function (Builder $query): void {
                    $query
                        ->selectRaw('1')
                        ->from('document_routes as pending_routes')
                        ->whereColumn(
                            'pending_routes.document_id',
                            'documents.id'
                        )
                        ->whereNull('pending_routes.received_at');
                })
                ->distinct()
                ->count('documents.id'),
        ];

        return response()->json([
            'filters' => [
                'month' => $validated['month'] ?? null,
                'timezone' => $timezone,
            ],
            'scope' => [
                'type' => $systemWide ? 'system' : 'office',
                'office' => $office
                    ? [
                        'id' => (int) $office->id,
                        'name' => $office->office_name,
                    ]
                    : null,
            ],
            'summary' => $summary,
            'status_distribution' => $this->statusDistribution($documents),
            'current_office_distribution' => $this->officeDistribution(
                $documents,
                'current_office_id'
            ),
            'origin_office_distribution' => $this->officeDistribution(
                $documents,
                'origin_office_id'
            ),
            'recent_documents' => $this->recentDocuments($documents),
            'recent_routing_activity' => $this->recentRoutingActivity(
                $officeId,
                $bounds
            ),
        ]);
    }

    private function rejectUnknownParameters(Request $request): void
    {
        $unknown = array_values(array_diff(
            array_keys($request->query()),
            ['month']
        ));

        if ($unknown === []) {
            return;
        }

        throw ValidationException::withMessages([
            $unknown[0] => 'This query parameter is not supported.',
        ]);
    }

    private function reportingTimezone(): ?DateTimeZone
    {
        $configured = config('reporting.timezone', 'Asia/Manila');

        if (!is_string($configured) || trim($configured) === '') {
            return null;
        }

        try {
            return new DateTimeZone($configured);
        } catch (Throwable) {
            return null;
        }
    }

    private function monthBounds(
        ?string $month,
        DateTimeZone $timezone
    ): ?array
    {
        if ($month === null) {
            return null;
        }

        $start = CarbonImmutable::createFromFormat(
            '!Y-m',
            $month,
            $timezone
        )->startOfMonth();

        return [
            $start->utc()->format('Y-m-d H:i:s'),
            $start->addMonth()->utc()->format('Y-m-d H:i:s'),
        ];
    }

    private function hasSystemScope(User $user): bool
    {
        return $user->hasRole('Administrator')
            || $user->hasRole('Records Officer');
    }

    private function resolveOfficeScope(User $user, bool $systemWide): ?object
    {
        if ($systemWide) {
            return null;
        }

        if ($user->office_id === null) {
            abort(403, 'Your user account is not assigned to an office.');
        }

        $office = DB::table('offices')
            ->select(['id', 'office_name'])
            ->where('id', $user->office_id)
            ->first();

        if (!$office) {
            abort(403, 'Your user account is not assigned to a valid office.');
        }

        return $office;
    }

    private function documentQuery(?int $officeId, ?array $bounds): Builder
    {
        $query = DB::table('documents');

        if ($officeId !== null) {
            $query->where(function (Builder $query) use ($officeId): void {
                $query
                    ->where('documents.origin_office_id', $officeId)
                    ->orWhere('documents.current_office_id', $officeId)
                    ->orWhereExists(function (Builder $route) use ($officeId): void {
                        $route
                            ->selectRaw('1')
                            ->from('document_routes as scope_routes')
                            ->whereColumn(
                                'scope_routes.document_id',
                                'documents.id'
                            )
                            ->where(function (Builder $route) use ($officeId): void {
                                $route
                                    ->where('scope_routes.from_office_id', $officeId)
                                    ->orWhere('scope_routes.to_office_id', $officeId);
                            });
                    });
            });
        }

        $this->applyBounds($query, 'documents.created_at', $bounds);

        return $query;
    }

    private function movementCount(
        string $officeColumn,
        ?int $officeId,
        ?array $bounds
    ): int {
        $query = DB::table('document_routes')
            ->whereNotNull('forwarded_at');

        if ($officeId !== null) {
            $query->where($officeColumn, $officeId);
        }

        $this->applyBounds($query, 'forwarded_at', $bounds);

        return $query->count();
    }

    private function statusDistribution(Builder $documents): array
    {
        return (clone $documents)
            ->leftJoin(
                'document_statuses',
                'document_statuses.id',
                '=',
                'documents.status_id'
            )
            ->select([
                'document_statuses.id',
                'document_statuses.status_name',
            ])
            ->selectRaw('COUNT(DISTINCT documents.id) as aggregate_count')
            ->groupBy([
                'document_statuses.id',
                'document_statuses.status_name',
            ])
            ->orderByRaw('document_statuses.id IS NULL')
            ->orderBy('document_statuses.id')
            ->get()
            ->map(fn (object $row): array => [
                'status' => [
                    'id' => $row->id !== null ? (int) $row->id : null,
                    'name' => $row->status_name ?? 'Unassigned',
                ],
                'count' => (int) $row->aggregate_count,
            ])
            ->all();
    }

    private function officeDistribution(
        Builder $documents,
        string $documentOfficeColumn
    ): array {
        return (clone $documents)
            ->leftJoin(
                'offices as distribution_offices',
                'distribution_offices.id',
                '=',
                "documents.{$documentOfficeColumn}"
            )
            ->select([
                'distribution_offices.id',
                'distribution_offices.office_name',
            ])
            ->selectRaw('COUNT(DISTINCT documents.id) as aggregate_count')
            ->groupBy([
                'distribution_offices.id',
                'distribution_offices.office_name',
            ])
            ->orderByRaw('distribution_offices.id IS NULL')
            ->orderBy('distribution_offices.office_name')
            ->orderBy('distribution_offices.id')
            ->get()
            ->map(fn (object $row): array => [
                'office' => [
                    'id' => $row->id !== null ? (int) $row->id : null,
                    'name' => $row->office_name ?? 'Unassigned',
                ],
                'count' => (int) $row->aggregate_count,
            ])
            ->all();
    }

    private function recentDocuments(Builder $documents): array
    {
        return (clone $documents)
            ->leftJoin(
                'document_statuses as recent_statuses',
                'recent_statuses.id',
                '=',
                'documents.status_id'
            )
            ->select([
                'documents.id',
                'documents.tracking_no',
                'documents.created_at',
                'recent_statuses.id as status_id',
                'recent_statuses.status_name',
            ])
            ->orderByDesc('documents.created_at')
            ->orderByDesc('documents.id')
            ->limit(self::RECENT_LIMIT)
            ->get()
            ->map(fn (object $row): array => [
                'id' => (int) $row->id,
                'tracking_no' => $row->tracking_no,
                'status' => [
                    'id' => $row->status_id !== null
                        ? (int) $row->status_id
                        : null,
                    'name' => $row->status_name ?? 'Unassigned',
                ],
                'created_at' => $this->serializeTimestamp($row->created_at),
            ])
            ->all();
    }

    private function recentRoutingActivity(
        ?int $officeId,
        ?array $bounds
    ): array {
        $forwarded = $this->activityQuery(
            'forwarded',
            'forwarded_at',
            1,
            $officeId,
            $bounds
        );
        $received = $this->activityQuery(
            'received',
            'received_at',
            2,
            $officeId,
            $bounds
        );

        $activity = DB::query()
            ->fromSub($forwarded->unionAll($received), 'routing_activity')
            ->orderByDesc('occurred_at')
            ->orderByDesc('route_id')
            ->orderByDesc('event_precedence')
            ->limit(self::RECENT_LIMIT)
            ->get();

        return $activity
            ->map(fn (object $row): array => [
                'document' => [
                    'id' => (int) $row->document_id,
                    'tracking_no' => $row->tracking_no,
                ],
                'event_type' => $row->event_type,
                'from_office' => [
                    'id' => (int) $row->from_office_id,
                    'name' => $row->from_office_name,
                ],
                'to_office' => [
                    'id' => (int) $row->to_office_id,
                    'name' => $row->to_office_name,
                ],
                'occurred_at' => $this->serializeTimestamp($row->occurred_at),
            ])
            ->all();
    }

    private function activityQuery(
        string $eventType,
        string $timestampColumn,
        int $eventPrecedence,
        ?int $officeId,
        ?array $bounds
    ): Builder {
        $query = DB::table('document_routes as activity_routes')
            ->join(
                'documents as activity_documents',
                'activity_documents.id',
                '=',
                'activity_routes.document_id'
            )
            ->join(
                'offices as from_offices',
                'from_offices.id',
                '=',
                'activity_routes.from_office_id'
            )
            ->join(
                'offices as to_offices',
                'to_offices.id',
                '=',
                'activity_routes.to_office_id'
            )
            ->whereNotNull("activity_routes.{$timestampColumn}")
            ->select([
                'activity_routes.id as route_id',
                'activity_routes.document_id',
                'activity_documents.tracking_no',
                'activity_routes.from_office_id',
                'from_offices.office_name as from_office_name',
                'activity_routes.to_office_id',
                'to_offices.office_name as to_office_name',
            ])
            ->selectRaw('? as event_type', [$eventType])
            ->selectRaw(
                "activity_routes.{$timestampColumn} as occurred_at"
            )
            ->selectRaw('? as event_precedence', [$eventPrecedence]);

        if ($officeId !== null) {
            $query->where(function (Builder $query) use ($officeId): void {
                $query
                    ->where('activity_routes.from_office_id', $officeId)
                    ->orWhere('activity_routes.to_office_id', $officeId);
            });
        }

        $this->applyBounds(
            $query,
            "activity_routes.{$timestampColumn}",
            $bounds
        );

        return $query;
    }

    private function applyBounds(
        Builder $query,
        string $column,
        ?array $bounds
    ): void {
        if ($bounds === null) {
            return;
        }

        $query
            ->where($column, '>=', $bounds[0])
            ->where($column, '<', $bounds[1]);
    }

    private function serializeTimestamp(?string $timestamp): ?string
    {
        if ($timestamp === null) {
            return null;
        }

        return CarbonImmutable::parse($timestamp, 'UTC')
            ->utc()
            ->toIso8601String();
    }
}
