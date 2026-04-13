<!DOCTYPE html>
<html lang="en">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <style>
            body { font-family: DejaVu Sans, sans-serif; font-size: 10px; color: #111; }
            h1 { font-size: 14px; margin: 0 0 6px; }
            .meta { font-size: 9px; color: #666; margin-bottom: 10px; }
            table { width: 100%; border-collapse: collapse; }
            th, td { border: 1px solid #ddd; padding: 6px; vertical-align: top; }
            th { background: #f5f5f5; font-weight: 700; }
            .nowrap { white-space: nowrap; }
        </style>
    </head>
    <body>
        <h1>Departments Export</h1>
        <div class="meta">Generated at: {{ $generatedAt->toDateTimeString() }}</div>

        <table>
            <thead>
                <tr>
                    <th class="nowrap">ID</th>
                    <th>Company</th>
                    <th>Branch</th>
                    <th>Parent</th>
                    <th>Manager</th>
                    <th>Name</th>
                    <th class="nowrap">Code</th>
                    <th class="nowrap">Status</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($departments as $department)
                    <tr>
                        <td class="nowrap">{{ $department->id }}</td>
                        <td>{{ $department->company?->name }}</td>
                        <td>{{ $department->branch?->name }}</td>
                        <td>{{ $department->parent?->name }}</td>
                        <td>{{ $department->manager?->name }}</td>
                        <td>{{ $department->name }}</td>
                        <td class="nowrap">{{ $department->code }}</td>
                        <td class="nowrap">{{ $department->status }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </body>
</html>

