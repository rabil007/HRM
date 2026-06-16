<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Offshore CV — {{ $full_name ?? 'Employee' }}</title>
    <style>
        @page { size: A4 portrait; margin: 0; }

        * { box-sizing: border-box; }

        html, body {
            font-family: DejaVu Sans, Arial, Helvetica, sans-serif;
            font-size: 8.5pt;
            line-height: 1.4;
            color: #1a1a1a;
            margin: 0;
            padding: 0;
            background: #fff;
        }

        @media screen {
            body:not(.pdf-output) {
                padding: 16px;
                background: #e5e7eb;
            }
            body:not(.pdf-output) .cv-shell {
                background: #fff;
                box-shadow: 0 2px 8px rgba(0,0,0,0.15);
            }
        }

        .toolbar {
            display: flex;
            justify-content: flex-end;
            gap: 8px;
            margin-bottom: 10px;
        }

        .toolbar button, .toolbar a.btn {
            border: 1px solid #999;
            background: #f5f5f5;
            color: #111;
            padding: 5px 12px;
            font-size: 10px;
            font-weight: 600;
            text-decoration: none;
            cursor: pointer;
        }

        .toolbar .primary { background: #1e3a5f; color: #fff; border-color: #1e3a5f; }

        @media print { .toolbar { display: none !important; } }

        /* ─── Main content wrapper ─── */
        .cv-body {
            padding: 14mm 14mm 12mm 14mm;
        }

        /* ─── Header ─── */
        .header-wrap {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 10px;
        }

        .header-wrap td { vertical-align: top; padding: 0; }

        .header-info { padding-right: 10px; }

        .emp-name {
            font-size: 20pt;
            font-weight: 700;
            color: #1a1a1a;
            margin: 0 0 6px;
            line-height: 1.15;
        }

        .header-field {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 2px;
        }

        .header-field td {
            padding: 1px 0;
            font-size: 8pt;
            vertical-align: top;
        }

        .header-field .f-label {
            font-weight: 700;
            width: 78px;
            color: #1a1a1a;
        }

        .header-field .f-value { color: #1a1a1a; }

        .portrait-cell {
            width: 90px;
            text-align: right;
            vertical-align: top !important;
        }

        .portrait {
            width: 82px;
            height: 100px;
            object-fit: cover;
            border: 1px solid #cbd5e1;
        }

        .portrait-placeholder {
            width: 82px;
            height: 100px;
            border: 1px dashed #94a3b8;
            background: #f8fafc;
            color: #94a3b8;
            font-size: 7pt;
            text-align: center;
            line-height: 100px;
        }

        hr.divider {
            border: none;
            border-top: 1px solid #1a1a1a;
            margin: 8px 0 6px;
        }

        /* ─── Section ─── */
        .section { margin-bottom: 10px; }

        .section-title {
            font-size: 9pt;
            font-weight: 700;
            letter-spacing: 0.06em;
            text-transform: uppercase;
            color: #1a1a1a;
            margin: 0 0 5px;
            border-bottom: 1px solid #1a1a1a;
            padding-bottom: 2px;
        }

        /* ─── Summary ─── */
        .summary-text {
            margin: 0;
            font-size: 8.5pt;
            text-align: justify;
            font-style: italic;
            padding-left: 20px;
        }

        /* ─── Competencies ─── */
        .competency-list {
            margin: 0;
            padding-left: 20px;
        }

        .competency-list li {
            margin-bottom: 3px;
            font-size: 8.5pt;
        }

        /* ─── Tables ─── */
        table.data-table {
            width: 100%;
            border-collapse: collapse;
            table-layout: fixed;
        }

        table.data-table th,
        table.data-table td {
            border: 1px solid #94a3b8;
            padding: 3px 5px;
            font-size: 7.5pt;
            vertical-align: top;
            word-wrap: break-word;
        }

        table.data-table th {
            background: #f1f5f9;
            font-weight: 700;
            text-align: center;
            font-size: 7pt;
            text-transform: uppercase;
        }

        .empty-row td {
            text-align: center;
            color: #64748b;
            font-style: italic;
            padding: 6px;
        }
    </style>
</head>
<body class="{{ !empty($is_pdf) ? 'pdf-output' : '' }}">
    @if (!empty($printable))
        <div class="toolbar">
            <button type="button" onclick="window.print()">Print</button>
            <a class="btn primary" href="?format=pdf&amp;inline=1" target="_blank">Download PDF</a>
        </div>
    @endif

    <div class="cv-shell">
        <div class="cv-body">

            {{-- Header --}}
            <table class="header-wrap">
                <tr>
                    <td class="header-info">
                        <h1 class="emp-name">{{ $full_name }}</h1>

                        <table class="header-field">
                            <tr>
                                <td class="f-label">Post on:</td>
                                <td class="f-value">{{ $position !== '' ? $position : '—' }}</td>
                            </tr>
                            <tr>
                                <td class="f-label">Visa Status:</td>
                                <td class="f-value">{{ $visa_status !== '' ? $visa_status : '' }}</td>
                            </tr>
                            <tr>
                                <td class="f-label">Contact:</td>
                                <td class="f-value">
                                    @if ($phone !== '' || $email !== '')
                                        {{ implode(' | ', array_filter([$phone, $email])) }}
                                    @else
                                        —
                                    @endif
                                </td>
                            </tr>
                        </table>
                    </td>
                    <td class="portrait-cell">
                        @if (!empty($portrait_url))
                            <img src="{{ $portrait_url }}" alt="Portrait" class="portrait">
                        @else
                            <div class="portrait-placeholder">Photo</div>
                        @endif
                    </td>
                </tr>
            </table>

            <hr class="divider">

            {{-- Professional Summary --}}
            <div class="section">
                <div class="section-title">Professional Summary</div>
                <p class="summary-text">{{ $professional_summary }}</p>
            </div>

            {{-- Core Competencies --}}
            <div class="section">
                <div class="section-title">Core Competencies</div>
                <ul class="competency-list">
                    <li><strong>Trade Skills:</strong> {{ $trade_skills }}</li>
                    <li><strong>Safety:</strong> {{ $safety_competencies }}</li>
                    <li><strong>Operations:</strong> {{ $operations_competencies }}</li>
                </ul>
            </div>

            {{-- Technical Certifications --}}
            <div class="section">
                <div class="section-title">Technical Certifications</div>
                <table class="data-table">
                    <thead>
                        <tr>
                            <th style="width:46%;">Certificate Name</th>
                            <th style="width:34%;">Issuing Body</th>
                            <th style="width:20%;">Expiry Date</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($certifications as $row)
                            <tr>
                                <td>{{ $row['certificate_name'] !== '' ? $row['certificate_name'] : '—' }}</td>
                                <td>{{ $row['issuing_body'] !== '' ? $row['issuing_body'] : '—' }}</td>
                                <td>{{ $row['expiry_date'] !== '' ? $row['expiry_date'] : '—' }}</td>
                            </tr>
                        @empty
                            <tr class="empty-row">
                                <td colspan="3">No certifications recorded.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            {{-- Offshore Project History --}}
            <div class="section">
                <div class="section-title">Offshore Project History</div>
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Vessel Name</th>
                            <th>Vessel Type</th>
                            <th>Rank</th>
                            <th>From</th>
                            <th>To</th>
                            <th>Total<br>Month</th>
                            <th>Total<br>Days</th>
                            <th>GRT</th>
                            <th>BHP</th>
                            <th>Company Name</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($offshore_projects as $row)
                            <tr>
                                <td>{{ $row['vessel_name'] !== '' ? $row['vessel_name'] : '—' }}</td>
                                <td>{{ $row['vessel_type'] !== '' ? $row['vessel_type'] : '—' }}</td>
                                <td>{{ $row['rank'] !== '' ? $row['rank'] : '—' }}</td>
                                <td>{{ $row['from'] !== '' ? $row['from'] : '—' }}</td>
                                <td>{{ $row['to'] !== '' ? $row['to'] : '—' }}</td>
                                <td>{{ $row['total_months'] }}</td>
                                <td>{{ $row['total_days'] }}</td>
                                <td>{{ $row['grt'] !== '' ? $row['grt'] : '—' }}</td>
                                <td>{{ $row['bhp'] !== '' ? $row['bhp'] : '—' }}</td>
                                <td>{{ $row['company_name'] !== '' ? $row['company_name'] : '—' }}</td>
                            </tr>
                        @empty
                            <tr class="empty-row">
                                <td colspan="10">No offshore project history recorded.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

        </div>{{-- .cv-body --}}
    </div>{{-- .cv-shell --}}
</body>
</html>
