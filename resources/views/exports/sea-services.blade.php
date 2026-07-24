<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Sea Services Export</title>
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #111827; }
        h1 { font-size: 18px; margin: 0 0 6px; }
        .meta { font-size: 11px; color: #6b7280; margin-bottom: 12px; }
        table { width: 100%; border-collapse: collapse; }
        th, td { border: 1px solid #e5e7eb; padding: 6px 8px; vertical-align: top; }
        th { background: #f3f4f6; text-align: left; font-weight: 700; }
    </style>
</head>
<body>
    <h1>Sea Services</h1>
    <div class="meta">Generated at: {{ $generatedAt->toDateTimeString() }}</div>

    <table>
        <thead>
        <tr>
            <th>Employee No</th>
            <th>Name</th>
            <th>Department</th>
            <th>Vessel</th>
            <th>Vessel Type</th>
            <th>Rank</th>
            <th>Client</th>
            <th>Start Date</th>
            <th>End Date</th>
            <th>Months</th>
            <th>Days</th>
            <th>Linked Assignment Phase</th>
        </tr>
        </thead>
        <tbody>
        @foreach($seaServices as $seaService)
            <tr>
                <td>{{ $seaService->employee?->employee_no }}</td>
                <td>{{ $seaService->employee?->name }}</td>
                <td>{{ $seaService->employee?->department?->name ?? '—' }}</td>
                <td>{{ $seaService->vessel?->name ?? '—' }}</td>
                <td>{{ $seaService->vesselType?->name ?? '—' }}</td>
                <td>{{ $seaService->rank?->name ?? '—' }}</td>
                <td>{{ $seaService->client?->name ?? '—' }}</td>
                <td>{{ optional($seaService->start_date)->toDateString() ?? '—' }}</td>
                <td>{{ optional($seaService->end_date)->toDateString() ?? '—' }}</td>
                <td>{{ $seaService->total_months }}</td>
                <td>{{ $seaService->total_days }}</td>
                <td>{{ $seaService->crew_assignment_phase_id ? 'Yes' : 'No' }}</td>
            </tr>
        @endforeach
        </tbody>
    </table>
</body>
</html>
