<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Training Export</title>
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
    <h1>Training</h1>
    <div class="meta">Generated at: {{ $generatedAt->toDateTimeString() }}</div>

    <table>
        <thead>
        <tr>
            <th>Employee No</th>
            <th>Name</th>
            <th>Department</th>
            <th>Position</th>
            <th>Course</th>
            <th>Issue Date</th>
            <th>Expiry Date</th>
            <th>Expiry Status</th>
            <th>Institute</th>
            <th>Country</th>
        </tr>
        </thead>
        <tbody>
        @foreach($trainings as $training)
            <tr>
                <td>{{ $training->employee?->employee_no }}</td>
                <td>{{ $training->employee?->name }}</td>
                <td>{{ $training->employee?->department?->name ?? '—' }}</td>
                <td>{{ $training->employee?->position?->title ?? '—' }}</td>
                <td>{{ $training->course?->name ?? '—' }}</td>
                <td>{{ optional($training->issue_date)->toDateString() ?? '—' }}</td>
                <td>{{ optional($training->expiry_date)->toDateString() ?? '—' }}</td>
                <td>{{ \App\Support\EmployeeTrainings\TrainingExpiry::humanLabel($training->expiry_date) }}</td>
                <td>{{ $training->institute_center ?? '—' }}</td>
                <td>{{ $training->country?->name ?? '—' }}</td>
            </tr>
        @endforeach
        </tbody>
    </table>
</body>
</html>
