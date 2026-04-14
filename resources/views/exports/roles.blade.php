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
        <h1>Roles Export</h1>
        <div class="meta">Generated at: {{ $generatedAt->toDateTimeString() }}</div>

        <table>
            <thead>
                <tr>
                    <th class="nowrap">ID</th>
                    <th>Name</th>
                    <th>Permissions</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($roles as $role)
                    <tr>
                        <td class="nowrap">{{ $role->id }}</td>
                        <td>{{ $role->name }}</td>
                        <td>{{ $role->permissions()->pluck('name')->implode(', ') }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </body>
</html>

