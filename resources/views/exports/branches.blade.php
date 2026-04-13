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
        <h1>Branches Export</h1>
        <div class="meta">Generated at: {{ $generatedAt->toDateTimeString() }}</div>

        <table>
            <thead>
                <tr>
                    <th class="nowrap">ID</th>
                    <th>Company</th>
                    <th>Name</th>
                    <th class="nowrap">Code</th>
                    <th>City</th>
                    <th class="nowrap">Country</th>
                    <th class="nowrap">Headquarters</th>
                    <th class="nowrap">Status</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($branches as $branch)
                    <tr>
                        <td class="nowrap">{{ $branch->id }}</td>
                        <td>{{ $branch->company?->name }}</td>
                        <td>{{ $branch->name }}</td>
                        <td class="nowrap">{{ $branch->code }}</td>
                        <td>{{ $branch->city }}</td>
                        <td class="nowrap">{{ $branch->country }}</td>
                        <td class="nowrap">{{ $branch->is_headquarters ? 'Yes' : 'No' }}</td>
                        <td class="nowrap">{{ $branch->status }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </body>
</html>

