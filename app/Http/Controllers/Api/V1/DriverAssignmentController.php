<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\DriverAssignmentResource;
use App\Models\JobAssignment;
use App\Services\DriverAssignmentWorkflow;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class DriverAssignmentController extends Controller
{
    public function __construct(private readonly DriverAssignmentWorkflow $workflow) {}

    public function active(Request $request): JsonResponse
    {
        $assignment = $this->workflow->active($request->user());

        return $this->success(
            $request,
            'Active assignment retrieved successfully',
            $assignment,
        );
    }

    public function attend(Request $request, string $assignment): JsonResponse
    {
        $data = $request->validate([
            'driverLatitude' => ['nullable', 'required_with:driverLongitude', 'numeric', 'between:-90,90'],
            'driverLongitude' => ['nullable', 'required_with:driverLatitude', 'numeric', 'between:-180,180'],
            'currentSpeedKmh' => ['nullable', 'numeric', 'between:0,300'],
        ]);
        $record = $this->workflow->attend($request->user(), $assignment, $data);

        return $this->success($request, 'Assignment started successfully.', $record);
    }

    public function decline(Request $request, string $assignment): JsonResponse
    {
        $data = $request->validate([
            'reason' => ['required', 'string', 'max:2000'],
            'additionalNotes' => ['nullable', 'string', 'max:2000'],
        ]);
        $reason = $data['reason'];
        if (! empty($data['additionalNotes'])) {
            $reason .= "\n".$data['additionalNotes'];
        }
        $record = $this->workflow->decline($request->user(), $assignment, $reason);

        return $this->success($request, 'Assignment declined', $record);
    }

    public function escalate(Request $request, string $assignment): JsonResponse
    {
        $data = $request->validate([
            'reason' => ['required', 'string', 'max:2000'],
        ]);
        $result = $this->workflow->escalate($request->user(), $assignment, $data['reason']);
        $payload = $this->assignmentPayload($request, $result['assignment']);
        $payload['escalationReason'] = $data['reason'];
        $payload['controlRoomRecipients'] = $result['notified'];
        $message = $result['notified'] > 0
            ? 'Assignment escalated to control room'
            : 'Escalation recorded, but no active control-room administrator was available to notify.';

        return response()->json([
            'success' => true,
            'message' => $message,
            'data' => $payload,
        ]);
    }

    public function needHelp(Request $request, string $assignment): JsonResponse
    {
        $helpType = $request->input('helpType');
        if (is_string($helpType)) {
            $request->merge([
                'helpType' => strtoupper(str_replace([' ', '-'], '_', trim($helpType))),
            ]);
        }

        $data = $request->validate([
            'helpType' => [
                'nullable',
                'required_without:reason',
                Rule::in([
                    'AMBULANCE',
                    'WATER_TANKER',
                    'VEHICLE_BREAKDOWN',
                    'ACCIDENT',
                    'MEDICAL_EMERGENCY',
                    'FIRE_EQUIPMENT_PROBLEM',
                    'ROUTE_PROBLEM',
                    'OTHER',
                ]),
            ],
            'reason' => ['nullable', 'required_without:helpType', 'string', 'max:2000'],
            'comments' => ['nullable', 'string', 'max:2000'],
        ]);
        $reason = $data['reason'] ?? str_replace('_', ' ', $data['helpType']);
        $result = $this->workflow->requestHelp(
            $request->user(),
            $assignment,
            $reason,
            $data['comments'] ?? null,
            $data['helpType'] ?? null,
        );
        $payload = $this->assignmentPayload($request, $result['assignment']);
        $payload['needHelpReason'] = $reason;
        $payload['helpType'] = $data['helpType'] ?? null;
        $payload['helpComments'] = $data['comments'] ?? null;
        $payload['controlRoomRecipients'] = $result['notified'];
        $message = $result['notified'] > 0
            ? 'Urgent help request sent to control room'
            : 'Help request recorded, but no active control-room administrator was available to notify.';

        return response()->json([
            'success' => true,
            'message' => $message,
            'data' => $payload,
        ]);
    }

    public function reached(Request $request, string $assignment): JsonResponse
    {
        $data = $request->validate([
            'reachedLatitude' => ['nullable', 'required_with:reachedLongitude', 'numeric', 'between:-90,90'],
            'reachedLongitude' => ['nullable', 'required_with:reachedLatitude', 'numeric', 'between:-180,180'],
            'reachedTime' => [
                'nullable',
                'date',
                'after_or_equal:'.now()->subDay()->toIso8601String(),
                'before_or_equal:'.now()->toIso8601String(),
            ],
        ]);
        $record = $this->workflow->reached($request->user(), $assignment, $data);

        return $this->success($request, 'Driver reached incident location', $record);
    }

    public function reportIssues(Request $request, string $assignment): JsonResponse
    {
        $data = $request->validate([
            'selectedIssues' => ['required', 'array', 'min:1', 'max:20'],
            'selectedIssues.*' => ['required', 'string', 'max:200'],
            'issueRemarks' => ['nullable', 'string', 'max:2000'],
        ]);
        $result = $this->workflow->reportIssues(
            $request->user(),
            $assignment,
            $data['selectedIssues'],
            $data['issueRemarks'] ?? null,
        );
        $payload = $this->assignmentPayload($request, $result['assignment']);
        $payload['selectedIssues'] = $result['selected_issues'];
        $payload['issueRemarks'] = $result['issue_remarks'];

        return response()->json([
            'success' => true,
            'message' => 'Issues reported successfully',
            'data' => $payload,
        ]);
    }

    public function complete(Request $request, string $assignment): JsonResponse
    {
        $data = $request->validate([
            'completionNotes' => ['nullable', 'string', 'max:2000'],
            'completedAt' => [
                'nullable',
                'date',
                'after_or_equal:'.now()->subDay()->toIso8601String(),
                'before_or_equal:'.now()->toIso8601String(),
            ],
            'driverLatitude' => ['nullable', 'required_with:driverLongitude', 'numeric', 'between:-90,90'],
            'driverLongitude' => ['nullable', 'required_with:driverLatitude', 'numeric', 'between:-180,180'],
            'currentSpeedKmh' => ['nullable', 'numeric', 'between:0,300'],
        ]);
        $record = $this->workflow->complete($request->user(), $assignment, $data);

        return $this->success($request, 'Assignment completed successfully', $record);
    }

    public function close(Request $request, string $assignment): JsonResponse
    {
        $data = $request->validate([
            'closeReason' => ['required', 'string', 'max:2000'],
        ]);
        $record = $this->workflow->close($request->user(), $assignment, $data['closeReason']);

        return $this->success($request, 'Assignment closed', $record);
    }

    private function success(
        Request $request,
        string $message,
        ?JobAssignment $assignment,
    ): JsonResponse {
        return response()->json([
            'success' => true,
            'message' => $message,
            'data' => $assignment ? $this->assignmentPayload($request, $assignment) : null,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function assignmentPayload(Request $request, JobAssignment $assignment): array
    {
        return DriverAssignmentResource::make($assignment)->resolve($request);
    }
}
