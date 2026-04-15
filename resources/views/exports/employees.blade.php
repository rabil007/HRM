<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Employees Export</title>
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 12px; color: #111827; }
        h1 { font-size: 18px; margin: 0 0 6px; }
        .meta { font-size: 11px; color: #6b7280; margin-bottom: 12px; }
        table { width: 100%; border-collapse: collapse; }
        th, td { border: 1px solid #e5e7eb; padding: 6px 8px; vertical-align: top; }
        th { background: #f3f4f6; text-align: left; font-weight: 700; }
    </style>
</head>
<body>
    <h1>Employees</h1>
    <div class="meta">Generated at: {{ $generatedAt->toDateTimeString() }}</div>

    <table>
        <thead>
        <tr>
            <th>ID</th>
            <th>Employee No</th>
            <th>Name</th>
            <th>Branch</th>
            <th>Department</th>
            <th>Position</th>
            <th>Manager</th>
            <th>Work Email</th>
            <th>Phone</th>
            <th>Status</th>
            <th>Hire Date</th>
        </tr>
        </thead>
        <tbody>
        @foreach($employees as $employee)
            <tr>
                <td>{{ $employee->id }}</td>
                <td>{{ $employee->employee_no }}</td>
                <td>{{ trim($employee->first_name.' '.$employee->last_name) }}</td>
                <td>{{ $employee->branch?->name ?? '—' }}</td>
                <td>{{ $employee->department?->name ?? '—' }}</td>
                <td>{{ $employee->position?->title ?? '—' }}</td>
                <td>{{ $employee->manager ? trim($employee->manager->first_name.' '.$employee->manager->last_name) : '—' }}</td>
                <td>{{ $employee->work_email ?? '—' }}</td>
                <td>{{ $employee->phone ?? '—' }}</td>
                <td>{{ $employee->status }}</td>
                <td>{{ optional($employee->hire_date)->toDateString() }}</td>
            </tr>
        @endforeach
        </tbody>
    </table>
</body>
</html>

