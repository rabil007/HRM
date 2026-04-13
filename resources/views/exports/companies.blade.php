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
        <h1>Companies Export</h1>
        <div class="meta">Generated at: {{ $generatedAt->toDateTimeString() }}</div>

        <table>
            <thead>
                <tr>
                    <th class="nowrap">ID</th>
                    <th>Name</th>
                    <th>Industry</th>
                    <th class="nowrap">Country</th>
                    <th class="nowrap">Currency</th>
                    <th>City</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($companies as $company)
                    <tr>
                        <td class="nowrap">{{ $company->id }}</td>
                        <td>{{ $company->name }}</td>
                        <td>{{ $company->industry }}</td>
                        <td class="nowrap">{{ $company->country?->code }}</td>
                        <td class="nowrap">{{ $company->currency?->code }}</td>
                        <td>{{ $company->city }}</td>
                        <td class="nowrap">{{ $company->status }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </body>
</html>

