<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Bank Accounts Export</title>
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
    <h1>Bank Accounts</h1>
    <div class="meta">Generated at: {{ $generatedAt->toDateTimeString() }}</div>

    <table>
        <thead>
        <tr>
            <th>ID</th>
            <th>Employee No</th>
            <th>Name</th>
            <th>Department</th>
            <th>Position</th>
            <th>Bank Name</th>
            <th>Routing Code</th>
            <th>IBAN / Account No</th>
            <th>Account Name</th>
            <th>Payment Method</th>
            <th>Type</th>
            <th>Created At</th>
        </tr>
        </thead>
        <tbody>
        @foreach($bankAccounts as $account)
            <tr>
                <td>{{ $account->id }}</td>
                <td>{{ $account->employee?->employee_no }}</td>
                <td>{{ $account->employee?->name }}</td>
                <td>{{ $account->employee?->department?->name ?? '—' }}</td>
                <td>{{ $account->employee?->position?->title ?? '—' }}</td>
                <td>{{ $account->bank?->name ?? '—' }}</td>
                <td>{{ $account->bank?->uae_routing_code_agent_id ?? '—' }}</td>
                <td style="font-family: monospace;">{{ $account->iban ?? '—' }}</td>
                <td>{{ $account->account_name ?? '—' }}</td>
                <td>{{ $account->employee?->salary_payment_method?->label() ?? 'Bank transfer' }}</td>
                <td>{{ $account->is_primary ? 'Primary' : 'Secondary' }}</td>
                <td>{{ optional($account->created_at)->toDateString() }}</td>
            </tr>
        @endforeach
        </tbody>
    </table>
</body>
</html>
