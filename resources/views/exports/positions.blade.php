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
        <h1>Positions Export</h1>
        <div class="meta">Generated at: {{ $generatedAt->toDateTimeString() }}</div>

        <table>
            <thead>
                <tr>
                    <th class="nowrap">ID</th>
                    <th>Company</th>
                    <th>Department</th>
                    <th>Title</th>
                    <th class="nowrap">Grade</th>
                    <th class="nowrap">Min Salary</th>
                    <th class="nowrap">Max Salary</th>
                    <th class="nowrap">Status</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($positions as $position)
                    <tr>
                        <td class="nowrap">{{ $position->id }}</td>
                        <td>{{ $position->company?->name }}</td>
                        <td>{{ $position->department?->name }}</td>
                        <td>{{ $position->title }}</td>
                        <td class="nowrap">{{ $position->grade }}</td>
                        <td class="nowrap">{{ $position->min_salary }}</td>
                        <td class="nowrap">{{ $position->max_salary }}</td>
                        <td class="nowrap">{{ $position->status }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </body>
</html>

