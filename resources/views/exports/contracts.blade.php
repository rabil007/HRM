<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Contracts Export</title>
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 9px; color: #111827; }
        h1 { font-size: 16px; margin: 0 0 6px; }
        .meta { font-size: 10px; color: #6b7280; margin-bottom: 12px; }
        table { width: 100%; border-collapse: collapse; }
        th, td { border: 1px solid #e5e7eb; padding: 4px 6px; vertical-align: top; }
        th { background: #f3f4f6; text-align: left; font-weight: 700; }
        .text-right { text-align: right; }
    </style>
</head>
<body>
    <h1>Contracts</h1>
    <div class="meta">Generated at: {{ $generatedAt->toDateTimeString() }}</div>

    @php $payrollCategory = $payrollCategory ?? ''; @endphp
    <table>
        <thead>
        <tr>
            <th>Emp No</th>
            <th>Name</th>
            <th>Department</th>
            <th>Position</th>
            <th>Labor Contract ID</th>
            <th>Start Date</th>
            <th>End Date</th>
            <th class="text-right">Basic</th>
            @if($payrollCategory !== 'crew')
                <th class="text-right">Housing</th>
                <th class="text-right">Transport</th>
            @endif
            @if($payrollCategory !== 'office')
                <th class="text-right">Suppl.</th>
                <th class="text-right">Site</th>
            @endif
            @if($payrollCategory !== 'crew')
                <th class="text-right">Other</th>
            @endif
            <th class="text-right">Total</th>
        </tr>
        </thead>
        <tbody>
        @foreach($contracts as $contract)
            @php
                $totalSalary = (float) $contract->basic_salary
                    + (float) $contract->housing_allowance
                    + (float) $contract->transport_allowance
                    + (float) $contract->supplementary_allowance
                    + (float) $contract->site_allowance
                    + (float) $contract->other_allowances;
            @endphp
            <tr>
                <td>{{ $contract->employee?->employee_no }}</td>
                <td>{{ $contract->employee?->name }}</td>
                <td>{{ $contract->employee?->department?->name ?? '—' }}</td>
                <td>{{ $contract->employee?->position?->title ?? '—' }}</td>
                <td>{{ $contract->labor_contract_id ?? '—' }}</td>
                <td>{{ optional($contract->start_date)->toDateString() ?? '—' }}</td>
                <td>{{ optional($contract->end_date)->toDateString() ?? '—' }}</td>
                <td class="text-right">{{ number_format((float) $contract->basic_salary, 2) }}</td>
                @if($payrollCategory !== 'crew')
                    <td class="text-right">{{ number_format((float) $contract->housing_allowance, 2) }}</td>
                    <td class="text-right">{{ number_format((float) $contract->transport_allowance, 2) }}</td>
                @endif
                @if($payrollCategory !== 'office')
                    <td class="text-right">{{ number_format((float) $contract->supplementary_allowance, 2) }}</td>
                    <td class="text-right">{{ number_format((float) $contract->site_allowance, 2) }}</td>
                @endif
                @if($payrollCategory !== 'crew')
                    <td class="text-right">{{ number_format((float) $contract->other_allowances, 2) }}</td>
                @endif
                <td class="text-right" style="font-weight: 700;">{{ number_format($totalSalary, 2) }}</td>
            </tr>
        @endforeach
        </tbody>
    </table>
</body>
</html>
