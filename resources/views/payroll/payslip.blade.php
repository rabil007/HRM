<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Payslip — {{ $employee_name ?? 'Employee' }}</title>
    <style>
        @page { size: A4 portrait; margin: 12mm; }
        * { box-sizing: border-box; }
        body {
            font-family: DejaVu Sans, Arial, Helvetica, sans-serif;
            font-size: 10pt;
            color: #111;
            margin: 0;
        }
        h1 { font-size: 18pt; margin: 0 0 4mm; }
        h2 { font-size: 11pt; margin: 0 0 3mm; }
        .muted { color: #555; }
        table { width: 100%; border-collapse: collapse; margin-top: 4mm; }
        th, td { border: 1px solid #ccc; padding: 6px 8px; text-align: left; }
        th { background: #f3f4f6; }
        .amount { text-align: right; white-space: nowrap; }
        .summary { margin-top: 6mm; width: 50%; margin-left: auto; }
        .summary td { border: none; padding: 4px 0; }
        .summary .label { font-weight: 600; }
        .toolbar { margin-bottom: 8px; }
        @media print { .toolbar { display: none; } }
    </style>
</head>
<body @class(['pdf-output' => !empty($is_pdf)])>
    @if (!empty($printable))
        <div class="toolbar">
            <a href="?format=pdf&inline=1" class="btn">Download PDF</a>
        </div>
    @endif

    <h1>Payslip</h1>
    <p class="muted">{{ $company_name }} · {{ $payroll_category_label }} payroll</p>

    <table>
        <tr>
            <th>Employee</th>
            <td>{{ $employee_name }} ({{ $employee_no }})</td>
            <th>Designation</th>
            <td>{{ $designation ?: '—' }}</td>
        </tr>
        <tr>
            <th>Pay period</th>
            <td>{{ $period_name }}</td>
            <th>Period dates</th>
            <td>{{ $period_start }} — {{ $period_end }}</td>
        </tr>
        <tr>
            <th>Payment date</th>
            <td>{{ $payment_date }}</td>
            <th>Issued on</th>
            <td>{{ $issued_on }}</td>
        </tr>
    </table>

    <h2>Earnings</h2>
    <table>
        <thead>
            <tr>
                <th>Component</th>
                <th class="amount">Amount ({{ $currency_code }})</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($earnings as $line)
                <tr>
                    <td>{{ $line['label'] }}</td>
                    <td class="amount">{{ $line['amount'] }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <h2>Deductions</h2>
    <table>
        <thead>
            <tr>
                <th>Component</th>
                <th class="amount">Amount ({{ $currency_code }})</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($deductions as $line)
                <tr>
                    <td>{{ $line['label'] }}</td>
                    <td class="amount">{{ $line['amount'] }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <table class="summary">
        <tr>
            <td class="label">Gross salary</td>
            <td class="amount">{{ $gross_salary }} {{ $currency_code }}</td>
        </tr>
        <tr>
            <td class="label">Total deductions</td>
            <td class="amount">{{ $total_deductions }} {{ $currency_code }}</td>
        </tr>
        <tr>
            <td class="label">Net salary</td>
            <td class="amount"><strong>{{ $net_salary }} {{ $currency_code }}</strong></td>
        </tr>
    </table>
</body>
</html>
