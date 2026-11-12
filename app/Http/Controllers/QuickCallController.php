<?php

namespace App\Http\Controllers;

use App\Http\Requests\QuickCallRequest;
use App\Models\EmergencyJob;
use App\Models\Ward;
use App\Services\IncidentWorkflow;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

class QuickCallController extends Controller
{
    public function __construct(private readonly IncidentWorkflow $workflow) {}

    public function create(Request $request)
    {
        $user = $request->user();
        $wards = Ward::query()->where('is_active', true);

        if (! $user->hasRole('SUPER_ADMIN') && ! $user->hasRole('CONTROL_ROOM_ADMIN')) {
            $stationIds = $user->fireStations()->pluck('fire_stations.id')
                ->merge($user->managedFireStations()->pluck('id'))
                ->unique();
            $wards->whereHas(
                'fireStations',
                fn (Builder $stations) => $stations->whereIn('fire_stations.id', $stationIds),
            );
        }

        return view('incidents.quick-call', [
            'wards' => $wards->orderBy('name')->get(['id', 'name', 'code']),
        ]);
    }

    public function store(QuickCallRequest $request)
    {
        $job = $this->workflow->register($request->user(), [
            ...$request->validated(),
            'call_source' => 'PHONE',
            'occurred_at' => now(),
        ]);

        return redirect()->route('app.jobs.quick-call.confirmation', $job);
    }

    public function confirmation(Request $request, int $job)
    {
        $incident = $this->workflow->findVisibleJobOrFail($request->user(), $job);
        abort_unless(
            $request->user()->hasPermission('jobs.view') || $incident->created_by === $request->user()->id,
            403,
        );

        return view('incidents.quick-call-confirmation', [
            'job' => $incident,
            'canViewDetails' => $request->user()->hasPermission('jobs.view'),
            'canAssign' => $request->user()->hasPermission('jobs.view')
                && $request->user()->hasPermission('jobs.assign')
                && in_array($incident->status, [
                    EmergencyJob::STATUS_RECEIVED,
                    EmergencyJob::STATUS_ASSIGNED,
                ], true),
        ]);
    }
}
