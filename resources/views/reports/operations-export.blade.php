<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>VMC Operations Report</title>
    <style>
        body { color: #172033; font-family: DejaVu Sans, sans-serif; font-size: 7px; }
        h1 { color: #123d72; font-size: 18px; margin: 0 0 8px; }
        h2 { background: #eaf1f8; color: #123d72; font-size: 10px; margin: 15px 0 5px; padding: 5px; }
        .brand { display: flex; align-items: center; gap: 12px; margin-bottom: 10px; }
        .brand img { height: 42px; }
        .meta { color: #526174; margin-bottom: 10px; line-height: 1.6; }
        table { border-collapse: collapse; margin-bottom: 10px; width: 100%; }
        th, td { border: 1px solid #d5deea; padding: 3px 4px; text-align: left; }
        th { background: #f3f6fa; }
        .note { color: #526174; font-size: 8px; margin-top: 16px; }
        thead { display: table-header-group; }
        tr { page-break-inside: avoid; }
    </style>
</head>
<body>
    <div class="brand">
        <img src="{{ public_path('images/vadodara-municipal-corporation-logo.jpeg') }}" alt="Vadodara Municipal Corporation">
        <h1>Incident Reports</h1>
    </div>
    <div class="meta">
        Period: {{ $report['filters']['from'] }} to {{ $report['filters']['to'] }}<br>
        Ward: {{ $report['filters']['ward_id'] ? (collect($report['wards_available'])->firstWhere('id', $report['filters']['ward_id'])['name'] ?? 'Selected ward') : 'All wards' }}<br>
        Station: {{ $stationName }}<br>
        Generated: {{ now()->format('Y-m-d H:i:s') }}<br>
        Total incidents: {{ number_format($report['summary']['total_jobs']) }} · Critical: {{ number_format($report['summary']['critical_jobs']) }} · High: {{ number_format($report['summary']['high_jobs']) }} · Resolved: {{ number_format($report['summary']['resolved_jobs']) }} · Pending: {{ number_format($report['summary']['pending_jobs']) }}
    </div>
    @foreach ($sections as $section)
        <h2>{{ $section['title'] }}</h2>
        @if ($section['rows'] !== [])
            <table>
                <thead><tr>@foreach ($section['headers'] as $header)<th>{{ $header }}</th>@endforeach</tr></thead>
                <tbody>
                    @foreach ($section['rows'] as $row)
                        <tr>@foreach ($row as $value)<td>{{ is_numeric($value) ? $value : (string) $value }}</td>@endforeach</tr>
                    @endforeach
                </tbody>
            </table>
        @else
            <p>No records for this section.</p>
        @endif
    @endforeach
    <p class="note">Response and resolution averages are descriptive operational metrics based on recorded transitions. They are not SLA guarantees.</p>
</body>
</html>
