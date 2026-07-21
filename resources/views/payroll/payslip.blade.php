<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Payslip — {{ $employee_name ?? 'Employee' }}</title>
    <style>
        @page { size: A4 portrait; margin: 12mm; }
        * { box-sizing: border-box; }
        body {
            font-family: Arial, Helvetica, sans-serif;
            font-size: 10pt;
            color: #000;
            margin: 0;
            line-height: 1.4;
            background: #fff;
        }
        .payslip-container {
            max-width: 210mm;
            margin: 0 auto;
            background: #fff;
            padding: 40px;
        }
        .pdf-output {
            background: #fff;
        }
        .pdf-output .payslip-container {
            max-width: none;
            margin: 0;
            padding: 0;
            box-shadow: none;
        }
        @media print {
            body { background: #fff; }
            .payslip-container { max-width: none; margin: 0; padding: 0; box-shadow: none; }
            .toolbar { display: none; }
        }
        
        h1 { font-size: 16pt; margin: 0 0 10px 0; font-weight: normal; color: #000; }
        .disclaimer { font-size: 8pt; color: #333; margin-bottom: 25px; line-height: 1.5; }
        
        .section-header {
            width: 100%;
            border-bottom: 1px solid #000;
            margin-bottom: 8px;
            padding-bottom: 4px;
        }
        .section-header table {
            width: 100%;
            border-collapse: collapse;
        }
        .section-header td {
            font-size: 10pt;
            padding: 0;
        }
        
        .info-grid {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 30px;
        }
        .info-grid > tbody > tr > td {
            width: 50%;
            vertical-align: top;
        }
        
        .info-table {
            width: 100%;
            border-collapse: collapse;
        }
        .info-table td {
            padding: 4px 0;
            vertical-align: top;
        }
        .info-label {
            font-weight: bold;
            width: 130px;
        }
        
        .data-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 30px;
        }
        .data-table th, .data-table td {
            text-align: left;
            padding: 6px 0;
        }
        .data-table th {
            font-weight: normal;
            font-size: 10pt;
            border-bottom: 1px solid #000;
            padding-bottom: 4px;
        }
        .data-table th.amount, .data-table td.amount {
            text-align: right;
        }
        .data-table tr.total-row td {
            font-weight: bold;
            padding-top: 10px;
        }

        .summary-wrapper {
            width: 100%;
            margin-top: 20px;
        }
        
        .summary-table {
            width: 50%;
            margin-left: auto;
            border-collapse: collapse;
        }
        .summary-table td {
            padding: 8px 0;
            border-top: 1px solid #000;
        }
        .summary-table tr.net-row td {
            font-weight: bold;
            font-size: 11pt;
            border-top: 2px solid #000;
            border-bottom: 2px solid #000;
        }
        
        .toolbar { margin-bottom: 15px; }
        @media print { .toolbar { display: none; } }
        
        .btn {
            display: inline-block;
            background: #3b82f6;
            color: #fff;
            padding: 6px 12px;
            text-decoration: none;
            border-radius: 4px;
            font-size: 9pt;
        }
    </style>
</head>
<body @class(['pdf-output' => !empty($is_pdf)])>
    @if (!empty($printable))
        <div class="toolbar" style="max-width: 210mm; margin: 20px auto 0;">
            @if (!empty($download_url))
                <a href="{{ $download_url }}" class="btn">Download PDF</a>
            @else
                <a href="?format=pdf" class="btn">Download PDF</a>
            @endif
        </div>
    @endif

    <div class="payslip-container">
        <div style="margin-bottom: 25px;">
            @if(!empty($company_logo))
                <img src="{{ $company_logo }}" alt="{{ $company_name }}" style="max-height: 50px;">
            @endif
        </div>

        <h1>Salary Slip - {{ $employee_name }} - {{ $period_name }}</h1>
        
        <div class="disclaimer">
            This payslip is electronically generated; therefore, no signature or company stamp is required. For any clarification or dispute, please contact us within three (3) days.
        </div>

        <div class="section-header">
            <table>
                <tr>
                    <td style="width: 50%;">Employee Information</td>
                    <td style="width: 50%;">Other Information</td>
                </tr>
            </table>
        </div>

        <table class="info-grid">
            <tr>
                <td style="padding-right: 20px;">
                    <table class="info-table">
                        <tr><td class="info-label">Name:</td><td>{{ $employee_name }}</td></tr>
                        <tr><td class="info-label">ID:</td><td>{{ $employee_no }}</td></tr>
                        <tr><td class="info-label">Designation:</td><td>{{ $designation ?: '—' }}</td></tr>
                        <tr><td class="info-label">Company:</td><td>{{ $company_name }}</td></tr>
                    </table>
                </td>
                <td>
                    <table class="info-table">
                        <tr><td class="info-label">Pay Period:</td><td>{{ $period_start }} - {{ $period_end }}</td></tr>
                        <tr><td class="info-label">Payment Date:</td><td>{{ $payment_date }}</td></tr>
                        <tr><td class="info-label">Issued On:</td><td>{{ $issued_on }}</td></tr>
                        <tr><td class="info-label">Payroll Type:</td><td>{{ $payroll_category_label }}</td></tr>
                    </table>
                </td>
            </tr>
        </table>

        @if(($payroll_category ?? '') === 'crew' && ! empty($crew_summary))
            <div class="section-header">
                <table>
                    <tr>
                        <td>Crew Attendance</td>
                    </tr>
                </table>
            </div>

            <table class="info-grid">
                <tr>
                    <td style="padding-right: 20px;">
                        <table class="info-table">
                            @foreach($crew_summary as $summaryRow)
                                <tr><td class="info-label">{{ $summaryRow['label'] }}:</td><td>{{ $summaryRow['value'] ?? '0' }}</td></tr>
                            @endforeach
                        </table>
                    </td>
                    <td></td>
                </tr>
            </table>
        @endif

    @if(!empty($earnings) && count($earnings) > 0)
    <table class="data-table">
        <thead>
            <tr>
                <th>Earnings</th>
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
            <tr class="total-row">
                <td>Total Earnings</td>
                <td class="amount">{{ $gross_salary }}</td>
            </tr>
        </tbody>
    </table>
    @endif

    @if(!empty($deductions) && count($deductions) > 0)
    <table class="data-table">
        <thead>
            <tr>
                <th>Deductions</th>
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
            <tr class="total-row">
                <td>Total Deductions</td>
                <td class="amount">{{ $total_deductions }}</td>
            </tr>
        </tbody>
    </table>
    @endif

    <div class="summary-wrapper">
        <table class="summary-table">
            <tr class="net-row">
                <td>Net Salary</td>
                <td class="amount">{{ $net_salary }} {{ $currency_code }}</td>
            </tr>
        </table>
    </div>
</div>

</body>
</html>
