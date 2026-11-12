<?php

namespace App\Http\Controllers;

use App\Http\Requests\OperationsReportRequest;
use App\Services\OperationsReportService;
use Illuminate\Http\JsonResponse;

class OperationsReportController extends Controller
{
    public function __construct(private readonly OperationsReportService $reports) {}

    public function index(OperationsReportRequest $request)
    {
        $report = $this->reports->report($request->user(), $request->validated());

        return view('reports.operations', ['report' => $report]);
    }

    public function data(OperationsReportRequest $request): JsonResponse
    {
        return response()->json(
            $this->reports->incidentTable($request->user(), $request->validated()),
        );
    }
}
