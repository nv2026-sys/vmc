<?php

namespace App\Http\Controllers;

use App\Http\Requests\OperationsReportRequest;
use App\Services\OperationsReportService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Response;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Drawing;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

class OperationsReportExportController extends Controller
{
    public function __construct(private readonly OperationsReportService $reports) {}

    public function export(OperationsReportRequest $request, string $format): Response|StreamedResponse
    {
        abort_unless(in_array($format, ['csv', 'xlsx', 'pdf'], true), 404);

        $report = $this->reports->report($request->user(), $request->validated());
        $stationName = $this->stationName($report['filters']['station_id'], $report['stations_available']);
        $sections = $this->sections($report);
        $rows = $this->rows($report, $stationName, $sections);
        $filename = sprintf('vmc-incident-report-%s-to-%s', $report['filters']['from'], $report['filters']['to']);

        return match ($format) {
            'csv' => $this->csv($rows, $filename),
            'xlsx' => $this->xlsx($rows, $filename),
            'pdf' => Pdf::loadView('reports.operations-export', [
                'report' => $report,
                'stationName' => $stationName,
                'sections' => $sections,
            ])->setPaper('a4', 'landscape')->download($filename.'.pdf'),
        };
    }

    private function csv(array $rows, string $filename): StreamedResponse
    {
        return response()->streamDownload(function () use ($rows): void {
            $output = fopen('php://output', 'wb');
            if ($output === false) {
                throw new \RuntimeException('Unable to open the report export stream.');
            }

            fwrite($output, "\xEF\xBB\xBF");
            foreach ($rows as $row) {
                fputcsv($output, array_map($this->safeSpreadsheetValue(...), $row));
            }
            fclose($output);
        }, $filename.'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private function xlsx(array $rows, string $filename): StreamedResponse
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Operations report');
        $sheet->fromArray(
            array_map(fn (array $row): array => array_map($this->safeSpreadsheetValue(...), $row), $rows),
            null,
            'A1',
            true,
        );
        $sheet->getStyle('C1')->getFont()->setBold(true)->setSize(16);
        $sheet->freezePane('A5');
        $logo = new Drawing;
        $logo->setName('Vadodara Municipal Corporation');
        $logo->setPath(public_path('images/vadodara-municipal-corporation-logo.jpeg'));
        $logo->setHeight(42);
        $logo->setCoordinates('A1');
        $logo->setWorksheet($sheet);

        foreach (range('A', 'Q') as $column) {
            $sheet->getColumnDimension($column)->setAutoSize(true);
        }

        $writer = new Xlsx($spreadsheet);

        return response()->streamDownload(
            fn () => $writer->save('php://output'),
            $filename.'.xlsx',
            ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
        );
    }

    private function safeSpreadsheetValue(mixed $value): mixed
    {
        if (is_string($value) && preg_match('/^\s*[=+\-@]/', $value) === 1) {
            return "'".$value;
        }

        return $value;
    }

    private function stationName(?int $stationId, array $stations): string
    {
        if ($stationId === null) {
            return 'All accessible stations';
        }

        $station = collect($stations)->firstWhere('id', $stationId);
        if ($station === null) {
            throw new \LogicException('The selected report station is not available in the validated scope.');
        }

        return $station['name'].' · '.$station['code'];
    }

    private function sections(array $report): array
    {
        $summary = $report['summary'];

        return [
            [
                'title' => 'Incident summary',
                'headers' => ['Metric', 'Value'],
                'rows' => [
                    ['Total incidents', $summary['total_jobs']],
                    ['High priority incidents', $summary['high_jobs']],
                    ['Pending', $summary['pending_jobs']],
                    ['Active', $summary['active_jobs']],
                    ['Completed', $summary['completed_jobs']],
                    ['Duplicate', $summary['duplicate_jobs']],
                    ['Transferred to 112', $summary['transferred_jobs']],
                    ['Critical priority', $summary['critical_jobs']],
                    ['Completion rate (%)', $summary['completion_rate']],
                    ['Average response (minutes)', $summary['average_response_minutes'] ?? 'Not available'],
                    ['Incidents with response time', $summary['jobs_with_response_time']],
                    ['Average resolution (minutes)', $summary['average_resolution_minutes'] ?? 'Not available'],
                    ['Incidents with resolution time', $summary['jobs_with_resolution_time']],
                ],
            ],
            [
                'title' => 'Daily incident volume',
                'headers' => ['Date', 'Incidents'],
                'rows' => array_map(fn (array $day): array => [$day['date'], $day['count']], $report['daily']),
            ],
            ...array_map(
                fn (string $title, string $key): array => [
                    'title' => $title,
                    'headers' => ['Category', 'Incidents', 'Share (%)'],
                    'rows' => array_map(
                        fn (array $item): array => [str_replace('_', ' ', $item['label']), $item['count'], $item['percentage']],
                        $report[$key],
                    ),
                ],
                ['Incident status', 'Priority', 'Incident type'],
                ['statuses', 'priorities', 'incident_types'],
            ),
            [
                'title' => 'Station deployment workload',
                'headers' => ['Fire station', 'Code', 'Deployments', 'Completed deployments'],
                'rows' => array_map(
                    fn (array $station): array => [
                        $station['name'],
                        $station['code'],
                        $station['deployments'],
                        $station['completed_deployments'],
                    ],
                    $report['stations'],
                ),
            ],
            [
                'title' => 'Ward-wise incident report',
                'headers' => ['Ward', 'Total', 'Critical', 'High', 'Medium', 'Low', 'Resolved', 'Pending', 'Avg. Response (min)', 'Avg. Resolution (min)'],
                'rows' => array_map(fn (array $ward): array => [
                    $ward['ward'],
                    $ward['total'],
                    $ward['critical'],
                    $ward['high'],
                    $ward['medium'],
                    $ward['low'],
                    $ward['resolved'],
                    $ward['pending'],
                    $ward['average_response'] ?? '--',
                    $ward['average_resolution'] ?? '--',
                ], $report['wards']),
            ],
            [
                'title' => 'Incident type-wise report',
                'headers' => ['Incident Type', 'Total Cases', 'Resolved', 'Pending', 'Avg. Response (min)', 'Share (%)'],
                'rows' => array_map(fn (array $type): array => [
                    $type['type'],
                    $type['total'],
                    $type['resolved'],
                    $type['pending'],
                    $type['average_response'] ?? '--',
                    $type['percentage'],
                ], $report['type_reports']),
            ],
            [
                'title' => 'Priority-wise report',
                'headers' => ['Priority', 'Total Cases', 'Resolved', 'Pending'],
                'rows' => array_map(fn (array $priority): array => [
                    $priority['priority'],
                    $priority['total'],
                    $priority['resolved'],
                    $priority['pending'],
                ], $report['priority_reports']),
            ],
            [
                'title' => 'Vehicle-wise incident report',
                'headers' => ['Vehicle', 'Assigned Incidents', 'Completed', 'Pending', 'Avg. Pickup (min)', 'Avg. Response (min)', 'Avg. Resolution (min)', 'Working Hours'],
                'rows' => array_map(fn (array $vehicle): array => [
                    $vehicle['vehicle'],
                    $vehicle['total'],
                    $vehicle['completed'],
                    $vehicle['pending'],
                    $vehicle['average_pickup'] ?? '--',
                    $vehicle['average_response'] ?? '--',
                    $vehicle['average_resolution'] ?? '--',
                    $vehicle['working_hours'],
                ], $report['vehicle_reports']),
            ],
            [
                'title' => 'Response time report',
                'headers' => ['Incident ID', 'Ward', 'Type', 'Created', 'Assigned', 'Departure', 'Arrival', 'Pickup (min)', 'Response (min)', 'Resolution (min)', 'Vehicle'],
                'rows' => array_map(fn (array $incident): array => [
                    $incident['job_id'],
                    $incident['ward'] ?? '--',
                    $incident['type'],
                    $incident['created_at']?->format('Y-m-d H:i:s') ?? '--',
                    $incident['assigned_at']?->format('Y-m-d H:i:s') ?? '--',
                    $incident['departure_at']?->format('Y-m-d H:i:s') ?? '--',
                    $incident['arrival_at']?->format('Y-m-d H:i:s') ?? '--',
                    $incident['pickup_minutes'] ?? '--',
                    $incident['response_minutes'] ?? '--',
                    $incident['resolution_minutes'] ?? '--',
                    $incident['vehicle_number'] ?? '--',
                ], $report['response_reports']),
            ],
        ];
    }

    private function rows(array $report, string $stationName, array $sections): array
    {
        $rows = [
            ['', '', 'Vadodara Municipal Corporation · Incident Reports'],
            ['Reporting period', $report['filters']['from'], 'to', $report['filters']['to']],
            ['Station', $stationName],
            ['Generated', now()->format('Y-m-d H:i:s')],
            ['Ward', $this->wardName($report)],
            [],
        ];

        foreach ($sections as $section) {
            $rows[] = [$section['title']];
            $rows[] = $section['headers'];
            array_push($rows, ...$section['rows']);
            $rows[] = [];
        }

        $rows[] = ['Incident Summary Report'];
        $rows[] = ['Sr. No.', 'Incident ID', 'Incident Date & Time', 'Incident Type', 'Incident Details', 'Location', 'Ward', 'Caller Name', 'Caller Phone', 'Priority', 'Assigned Vehicle', 'Vehicle Status', 'Incident Status', 'Pickup Time', 'Response Time', 'Elapsed Time', 'Resolution Time'];
        foreach ($report['response_reports'] as $index => $item) {
            $rows[] = [
                $index + 1,
                $item['job_id'],
                $item['created_at']?->format('Y-m-d H:i:s') ?? '--',
                $item['type'],
                $item['details'] ?? '--',
                $item['location'],
                $item['ward'] ?? '--',
                $item['caller_name'],
                $item['caller_phone'],
                $item['priority'],
                $item['vehicle_number'] ?? '--',
                $item['vehicle_status'] ?? '--',
                $item['status'],
                $this->minutes($item['pickup_minutes'] ?? null),
                $this->minutes($item['response_minutes'] ?? null),
                $this->duration($item['elapsed_seconds'] ?? null),
                $this->minutes($item['resolution_minutes'] ?? null),
            ];
        }

        return $rows;
    }

    private function wardName(array $report): string
    {
        if ($report['filters']['ward_id'] === null) {
            return 'All wards';
        }

        return collect($report['wards_available'])->firstWhere('id', $report['filters']['ward_id'])['name'] ?? 'Selected ward';
    }

    private function minutes(?float $value): string
    {
        return $value === null ? '--' : number_format($value, 1).' min';
    }

    private function duration(?int $seconds): string
    {
        if ($seconds === null) {
            return '--';
        }

        $minutes = max(0, intdiv($seconds, 60));
        $days = intdiv($minutes, 1440);
        $hours = intdiv($minutes % 1440, 60);
        $remainingMinutes = $minutes % 60;

        if ($days > 0) {
            return "{$days}d {$hours}h";
        }

        return $hours > 0 ? "{$hours}h {$remainingMinutes}m" : "{$remainingMinutes}m";
    }
}
