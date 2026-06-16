<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Salary Certificate — {{ $employee_name ?? 'Employee' }}</title>
    <style>
        @page { size: A4 portrait; margin: 0; }

        * { box-sizing: border-box; }

        html, body {
            font-family: DejaVu Sans, Arial, Helvetica, sans-serif;
            font-size: 10.5pt;
            line-height: 1.35;
            color: #000;
            margin: 0;
            padding: 0;
            background: #fff;
        }

        table { border-collapse: collapse; }

        table.page-wrap {
            width: 100%;
            border: none;
            table-layout: fixed;
        }

        table.page-wrap td.page-cell {
            border: none;
            padding: 11mm 14mm 16mm;
            vertical-align: top;
        }

        @media screen {
            body:not(.pdf-output) {
                padding: 16px;
                background: #f3f4f6;
            }

            body:not(.pdf-output) .page-shell {
                max-width: 210mm;
                margin: 0 auto;
                background: #fff;
                box-shadow: 0 1px 4px rgba(0, 0, 0, 0.12);
            }
        }

        .toolbar {
            display: flex;
            justify-content: flex-end;
            gap: 8px;
            margin-bottom: 8px;
        }

        .toolbar button, .toolbar a.btn {
            border: 1px solid #999;
            background: #f5f5f5;
            color: #111;
            padding: 5px 10px;
            font-size: 10px;
            font-weight: 600;
            text-decoration: none;
            cursor: pointer;
        }

        .toolbar .primary { background: #004080; color: #fff; border-color: #004080; }

        @media print { .toolbar { display: none !important; } }

        table.header {
            width: 100%;
            margin-bottom: 10mm;
        }

        table.header td {
            border: none;
            padding: 0;
            vertical-align: top;
        }

        table.header .logo img {
            max-height: 52px;
            max-width: 150px;
            width: auto;
            height: auto;
        }

        table.header .date {
            text-align: right;
            font-size: 10.5pt;
            padding-top: 4mm;
        }

        .subject {
            font-size: 11pt;
            font-weight: 700;
            text-decoration: underline;
            margin: 0 0 5mm;
        }

        p {
            margin: 0 0 4.5mm;
            text-align: left;
        }

        table.details-table {
            width: 72%;
            margin: 6mm auto 7mm;
            border-collapse: collapse;
            table-layout: fixed;
        }

        table.details-table td {
            border: 1px solid #000;
            padding: 4px 8px;
            vertical-align: middle;
            font-size: 10.5pt;
            line-height: 1.3;
        }

        table.details-table .label {
            width: 44%;
        }

        table.details-table .value {
            width: 56%;
        }

        .hr-email {
            color: #0000ff;
            font-weight: 700;
        }

        table.signature-row {
            width: 100%;
            margin-top: 8mm;
            border: none;
        }

        table.signature-row td {
            border: none;
            padding: 0;
            vertical-align: bottom;
        }

        table.signature-row .signature-cell {
            width: 45%;
        }

        table.signature-row .stamp-cell {
            width: 55%;
            text-align: left;
            padding-left: 4mm;
        }

        table.signature-row img.signature {
            max-height: 48px;
            max-width: 160px;
            width: auto;
            height: auto;
        }

        table.signature-row img.stamp {
            max-height: 82px;
            max-width: 240px;
            width: auto;
            height: auto;
        }

        table.page-footer {
            width: 100%;
            margin-top: 14mm;
            border-top: 1px solid #000;
        }

        table.page-footer td {
            border: none;
            padding-top: 3mm;
            font-size: 9.5pt;
            vertical-align: top;
        }

        table.page-footer .page-label {
            width: 20%;
            text-align: left;
        }

        table.page-footer .page-number {
            width: 60%;
            text-align: center;
        }

        table.page-footer .page-spacer {
            width: 20%;
        }
    </style>
</head>
<body @class(['pdf-output' => $is_pdf ?? false])>
    @if ($printable ?? false)
        <div class="toolbar">
            <button type="button" onclick="window.print()">Print</button>
            <a class="btn primary" href="{{ request()->fullUrlWithQuery(['format' => 'pdf', 'inline' => 1]) }}" target="_blank">View A4 PDF</a>
        </div>
    @endif

    <div class="page-shell">
        <table class="page-wrap">
            <tr>
                <td class="page-cell">
                    <table class="header">
                        <tr>
                            <td class="logo" style="width: 50%;">
                                @if (! empty($company_logo_url))
                                    <img src="{{ $company_logo_url }}" alt="Company logo">
                                @endif
                            </td>
                            <td class="date" style="width: 50%;">{{ $issued_on }}</td>
                        </tr>
                    </table>

                    <p class="subject">Subject: Salary Certificate.</p>

                    <p>Dear Sir/Madam,</p>

                    <p>
                        This is to certify that the following individual is employed at
                        {{ $company_name }}
                        on a {{ $employment_basis }} basis:
                    </p>

                    <table class="details-table">
                        <tr>
                            <td class="label">Employee Name</td>
                            <td class="value">{{ $employee_name }}</td>
                        </tr>
                        <tr>
                            <td class="label">Emirate ID</td>
                            <td class="value">{{ $emirates_id }}</td>
                        </tr>
                        <tr>
                            <td class="label">Passport Number</td>
                            <td class="value">{{ $passport_number }}</td>
                        </tr>
                        <tr>
                            <td class="label">Nationality</td>
                            <td class="value">{{ $nationality }}</td>
                        </tr>
                        <tr>
                            <td class="label">Designation</td>
                            <td class="value">{{ $designation }}</td>
                        </tr>
                        <tr>
                            <td class="label">Start Date</td>
                            <td class="value">{{ $start_date }}</td>
                        </tr>
                        <tr>
                            <td class="label">Monthly Basic Salary</td>
                            <td class="value">{{ $basic_salary }} {{ $currency_code }}</td>
                        </tr>
                        <tr>
                            <td class="label">Total Salary</td>
                            <td class="value">{{ $total_salary }} {{ $currency_code }}</td>
                        </tr>
                    </table>

                    <p>
                        This letter was issued at the request of the employee and does not constitute any legal obligation or guarantee on the part of {{ $company_name }}.
                    </p>

                    @if (filled($hr_email))
                        <p>
                            Should you require any further information please contact our Human Resource Department on
                            <span class="hr-email">{{ $hr_email }}</span>.
                        </p>
                    @endif

                    <p style="margin-top: 7mm;">Sincerely,</p>

                    @if (! empty($signature_image_url) || ! empty($stamp_image_url))
                        <table class="signature-row">
                            <tr>
                                <td class="signature-cell">
                                    @if (! empty($signature_image_url))
                                        <img src="{{ $signature_image_url }}" alt="Signature" class="signature">
                                    @endif
                                </td>
                                <td class="stamp-cell">
                                    @if (! empty($stamp_image_url))
                                        <img src="{{ $stamp_image_url }}" alt="Company stamp" class="stamp">
                                    @endif
                                </td>
                            </tr>
                        </table>
                    @endif

                    <table class="page-footer">
                        <tr>
                            <td class="page-label">Page</td>
                            <td class="page-number">1</td>
                            <td class="page-spacer">&nbsp;</td>
                        </tr>
                    </table>
                </td>
            </tr>
        </table>
    </div>
</body>
</html>
