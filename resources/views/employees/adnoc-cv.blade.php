<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>ADNOC CV — {{ $full_name ?? 'Employee' }}</title>
    <style>
        /* Sizing matched to resources/cv-templates/adnoc-seafarer-cv-reference.pdf (new.pdf) */
        @page { size: A4 portrait; margin: 0; }

        * { box-sizing: border-box; }

        html, body {
            font-family: DejaVu Sans, Arial, Helvetica, sans-serif;
            font-size: 6.2pt;
            line-height: 1.15;
            color: #000;
            margin: 0;
            padding: 0;
            background: #fff;
        }

        table.cv-margin-wrap {
            width: 100%;
            border: none;
            border-collapse: collapse;
            table-layout: fixed;
        }

        table.cv-margin-wrap td.cv-margin-cell {
            border: none;
            padding: 11mm 18mm;
            vertical-align: top;
        }

        .cv-page-header-repeat {
            page-break-before: always;
            margin-bottom: 2px;
        }

        @media screen {
            body:not(.pdf-output) {
                padding: 16px;
                background: #f3f4f6;
            }
            body:not(.pdf-output) .cv-shell {
                background: #fff;
                padding: 4mm;
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

        .page-break-before { page-break-before: always; }

        table.cv {
            width: 100%;
            border-collapse: collapse;
            table-layout: fixed;
        }

        table.cv td {
            border: 1px solid #000;
            padding: 2px 4px;
            vertical-align: middle;
            word-wrap: normal;
            overflow-wrap: normal;
            word-break: normal;
        }

        table.cv-head {
            width: 100%;
            border-collapse: collapse;
            table-layout: fixed;
        }

        table.cv-head td {
            border: none;
            padding: 2px 6px;
            vertical-align: middle;
        }

        .section {
            background: #e36c0a;
            color: #fff;
            font-size: 7.5pt;
            font-weight: 700;
            text-align: left;
            text-transform: uppercase;
            padding: 3px 5px;
            border: 1px solid #000;
        }

        .section-start {
            border-top: none;
        }

        .head-logo-cell {
            text-align: right;
            vertical-align: middle;
            padding: 0;
            width: 16.67%;
        }

        .head-logo-cell--left {
            text-align: left;
        }

        .head-logo-cell img {
            max-height: 42px;
            max-width: 100%;
            width: auto;
            height: auto;
        }

        .head-title-cell {
            text-align: center;
            vertical-align: middle;
            padding: 0 4px;
            width: 66.66%;
        }

        table.cv-head tr.cv-head-brand td {
            vertical-align: middle;
        }

        .head-subtitle {
            font-size: 11pt;
            font-weight: 700;
            color: #c00000;
            line-height: 1.1;
        }

        .head-meta .lbl {
            border: none;
            padding-top: 4px;
        }

        .head-source {
            text-align: right;
            font-size: 6.2pt;
            color: #666;
            text-transform: uppercase;
            padding-top: 3px;
            vertical-align: bottom;
        }

        .lbl {
            font-weight: 700;
            font-size: 6.2pt;
            text-transform: uppercase;
        }

        .val { font-size: 7pt; }

        .val-nowrap {
            font-size: 6pt;
            white-space: nowrap;
        }

        .col-h {
            font-weight: 700;
            font-size: 5.5pt;
            text-align: center;
            text-transform: uppercase;
            white-space: nowrap;
        }

        .col-h-wrap {
            font-weight: 700;
            font-size: 5.5pt;
            text-align: center;
            text-transform: uppercase;
            line-height: 1.05;
        }

        .center { text-align: center; }

        .yn-h { font-weight: 700; font-size: 6.2pt; text-align: center; }
        .yn-c { text-align: center; font-size: 9pt; }

        .details-row td { font-size: 6.2pt; font-style: italic; color: #333; }

        .blank td { height: 9px; padding: 2px 4px; }

        .blank-compact td { height: 8px; padding: 2px 4px; }

        .remarks-space td { height: 28px; vertical-align: top; }

        .cv-bottom-spacer td {
            border: none !important;
            height: 10mm;
            padding: 0;
            line-height: 0;
        }

        .experience-summary td {
            background: #fde9d9;
        }

        .declaration { font-size: 6.2pt; line-height: 1.3; padding: 3px 4px; }

        .note {
            font-size: 6.2pt;
            font-weight: 700;
            text-align: center;
            padding: 2px 4px;
            background: #ffff00;
        }

        .footer-rev td {
            border: none !important;
            font-size: 6pt;
            text-align: right;
            padding: 6px 2px 0;
        }

        .footer-rev--final td {
            padding-top: 2px;
            padding-bottom: 4mm;
        }

        tr.sea-data-row td {
            font-size: 6pt;
            text-align: center;
            padding: 2px 3px;
            word-wrap: normal;
            overflow-wrap: normal;
        }

        tr.sea-data-row td.sea-date {
            white-space: nowrap;
            font-size: 5.5pt;
        }

        tr.sea-data-row td.sea-company { text-align: left; }

    </style>
</head>
<body @class(['pdf-output' => $is_pdf ?? false])>
    @if ($printable ?? true)
        <div class="toolbar">
            <button type="button" onclick="window.close()">Close</button>
            <a class="btn" href="{{ request()->fullUrlWithQuery(['format' => 'pdf', 'inline' => 0]) }}">Download PDF</a>
            <a class="btn primary" href="{{ request()->fullUrlWithQuery(['format' => 'pdf', 'inline' => 1]) }}" target="_blank">View A4 PDF</a>
        </div>
    @endif

    <div class="cv-shell">
        <table class="cv-margin-wrap"><tr><td class="cv-margin-cell">

        @include('employees.partials.adnoc-cv-header')

        {{-- PAGE 1 --}}
        <table class="cv">
            <colgroup>
                @for ($i = 0; $i < 12; $i++)
                    <col style="width:8.333%">
                @endfor
            </colgroup>

            <tr><td colspan="12" class="section section-start">SECTION 1 - PERSONAL DATA</td></tr>
            <tr>
                <td colspan="2" class="lbl">POSITION APPLIED</td>
                <td colspan="10" class="val">{{ $position_applied }}</td>
            </tr>
            <tr>
                <td colspan="2" class="lbl">FULL NAME</td>
                <td colspan="10" class="val">{{ $full_name }}</td>
            </tr>
            <tr>
                <td colspan="2" class="lbl">DATE OF BIRTH &amp; AGE</td>
                <td colspan="2" class="val">{{ $dob_age }}</td>
                <td colspan="2" class="lbl">RELIGION</td>
                <td colspan="2" class="val">{{ $religion }}</td>
                <td colspan="2" class="lbl">NATIONALITY</td>
                <td colspan="2" class="val">{{ $nationality }}</td>
            </tr>
            <tr>
                <td colspan="2" class="lbl">PASSPORT NUMBER</td>
                <td colspan="2" class="val">{{ $passport_number }}</td>
                <td colspan="2" class="lbl">ISSUE DATE</td>
                <td colspan="2" class="val">{{ $passport_issue }}</td>
                <td colspan="2" class="lbl">EXPIRY DATE</td>
                <td colspan="2" class="val">{{ $passport_expiry }}</td>
            </tr>
            <tr>
                <td colspan="2" class="lbl">PLACE OF BIRTH</td>
                <td colspan="2" class="val">{{ $place_of_birth }}</td>
                <td colspan="2" class="lbl">Body Weight (in Kg) &amp; Hight (in cm)</td>
                <td colspan="2" class="val">{{ $weight_height }}</td>
                <td colspan="2" class="lbl">NEAREST AIRPORT</td>
                <td colspan="2" class="val">{{ $nearest_airport }}</td>
            </tr>
            <tr>
                <td colspan="2" class="lbl">MARITAL STATUS (M/S)</td>
                <td colspan="2" class="val">{{ $marital_status }}</td>
                <td colspan="2" class="lbl">NAME OF SPOUSE</td>
                <td colspan="2" class="val">{{ $spouse_name }}</td>
                <td colspan="2" class="lbl">NO. OF CHILDREN</td>
                <td colspan="2" class="val">{{ $children_count }}</td>
            </tr>
            <tr>
                <td colspan="2" class="lbl">SEAMAN BOOK NO.</td>
                <td colspan="2" class="val">{{ $seaman_book_no }}</td>
                <td colspan="2" class="lbl">ISSUE DATE</td>
                <td colspan="2" class="val">{{ $seaman_issue }}</td>
                <td colspan="2" class="lbl">EXPIRY DATE</td>
                <td colspan="2" class="val">{{ $seaman_expiry }}</td>
            </tr>

            <tr><td colspan="12" class="section">SECTION 2 - CONTACT DETAILS</td></tr>
            <tr>
                <td colspan="2" class="lbl">PERMANENT ADDRESS (Home country)</td>
                <td colspan="10" class="val">{{ $permanent_address }}</td>
            </tr>
            <tr>
                <td colspan="2" class="lbl">CONTACT NUMBER (Home Country)</td>
                <td colspan="2" class="val">{{ $phone_home_country }}</td>
                <td colspan="2" class="lbl">MOBILE NUMBER (WITH CODES)</td>
                <td colspan="2" class="val">{{ $mobile_phone }}</td>
                <td colspan="2" class="lbl">RESIDENCE NUMBER</td>
                <td colspan="2" class="val">{{ $residence_number }}</td>
            </tr>
            <tr>
                <td colspan="2" class="lbl">SKYPE ID</td>
                <td colspan="4" class="val">{{ $skype_id }}</td>
                <td colspan="2" class="lbl">Email ID</td>
                <td colspan="4" class="val">{{ $email }}</td>
            </tr>
            <tr>
                <td colspan="2" rowspan="2" class="lbl">CONTACT NAME &amp; NUMBER DURING EMERGENCIES</td>
                <td colspan="5" class="col-h">{{ $emergency_uae_label }}</td>
                <td colspan="5" class="col-h">{{ $emergency_home_label }}</td>
            </tr>
            <tr>
                <td colspan="5" class="val">{{ $emergency_uae }}</td>
                <td colspan="5" class="val">{{ $emergency_home }}</td>
            </tr>
            <tr>
                <td colspan="2" class="lbl">NAME OF RELATIVES WORKING AT ADNOC L&amp;S, if any.</td>
                <td colspan="2" class="col-h">NAME</td>
                <td colspan="3" class="val">{{ $relative_name }}</td>
                <td colspan="2" class="col-h">RELATIONSHIP</td>
                <td colspan="3" class="val">{{ $relative_relationship }}</td>
            </tr>
            <tr>
                <td colspan="7" class="val">&nbsp;</td>
                <td colspan="2" class="col-h">DEPARTMENT</td>
                <td colspan="3" class="val">{{ $relative_department }}</td>
            </tr>

            <tr><td colspan="12" class="section">SECTION 3 - EDUCATIONAL QUALIFICATIONS (highest)</td></tr>
            <tr>
                <td colspan="2" class="col-h">DEGREE/COURSE</td>
                <td colspan="2" class="col-h">MAJOR/SUBJECT</td>
                <td colspan="2" class="col-h">ISSUE DATE</td>
                <td colspan="3" class="col-h">UNIVERSITY</td>
                <td colspan="3" class="col-h">COUNTRY</td>
            </tr>
            @forelse ($educations as $edu)
                <tr>
                    <td colspan="2" class="val">{{ $edu['degree'] }}</td>
                    <td colspan="2" class="val">{{ $edu['major'] }}</td>
                    <td colspan="2" class="val center">{{ $edu['issue_date'] }}</td>
                    <td colspan="3" class="val">{{ $edu['university'] }}</td>
                    <td colspan="3" class="val">{{ $edu['country'] }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="12" class="center" style="padding:4px;">No education records</td>
                </tr>
            @endforelse

            <tr><td colspan="12" class="section">SECTION 4 - CERTIFICATE OF COMPETENCY/LICENCE/ENDORSEMENT (highest)</td></tr>
            <tr>
                <td colspan="2" class="col-h">CAPACITY</td>
                <td colspan="2" class="col-h">REGULATION</td>
                <td colspan="2" class="col-h">ISSUE DATE</td>
                <td colspan="2" class="col-h">EXPIRY DATE</td>
                <td colspan="2" class="col-h">ISSUING AUTHORITY</td>
                <td colspan="1" class="col-h">COUNTRY</td>
                <td colspan="1" class="col-h">LIMITATIONS</td>
            </tr>
            @forelse ($coc_certificates as $coc)
                <tr>
                    <td colspan="2" class="val">{{ $coc['capacity'] }}</td>
                    <td colspan="2" class="val">{{ $coc['regulation'] }}</td>
                    <td colspan="2" class="val center val-nowrap">{{ $coc['issue_date'] }}</td>
                    <td colspan="2" class="val center val-nowrap">{{ $coc['expiry_date'] }}</td>
                    <td colspan="2" class="val">{{ $coc['issuing_authority'] }}</td>
                    <td colspan="1" class="val val-nowrap">{{ $coc['country'] }}</td>
                    <td colspan="1" class="val val-nowrap">{{ $coc['limitations'] }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="12" class="center" style="padding:4px;">No certificate records</td>
                </tr>
            @endforelse

            <tr><td colspan="12" class="section">SECTION 5 - DP CERTIFICATION/LICENCE (highest)</td></tr>
            <tr>
                <td colspan="2" class="col-h">CERTIFICATE</td>
                <td colspan="2" class="col-h">CERTIFICATE NUMBER</td>
                <td colspan="2" class="col-h">ISSUE DATE</td>
                <td colspan="2" class="col-h">EXPIRY DATE</td>
                <td colspan="2" class="col-h">ISSUING AUTHORITY</td>
                <td colspan="1" class="col-h">COUNTRY</td>
                <td colspan="1" class="col-h">LIMITATIONS</td>
            </tr>
            @forelse ($dp_certifications as $dp)
                <tr>
                    <td colspan="2" class="val">{{ $dp['certificate'] }}</td>
                    <td colspan="2" class="val">{{ $dp['certificate_number'] }}</td>
                    <td colspan="2" class="val center">{{ $dp['issue_date'] }}</td>
                    <td colspan="2" class="val center">{{ $dp['expiry_date'] }}</td>
                    <td colspan="2" class="val">{{ $dp['issuing_authority'] }}</td>
                    <td colspan="1" class="val val-nowrap">{{ $dp['country'] }}</td>
                    <td colspan="1" class="val val-nowrap">{{ $dp['limitations'] }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="12" class="center" style="padding:4px;">No DP certification records</td>
                </tr>
            @endforelse

            <tr><td colspan="12" class="section">SECTION 6 - STCW/OTHER TRAINING/PROFESSIONAL COURSES DETAILS</td></tr>
            @include('employees.partials.adnoc-cv-stcw-columns')
            @forelse ($stcw_courses as $course)
                <tr>
                    <td colspan="5" class="val">{{ $course['name'] }}</td>
                    <td colspan="2" class="val center val-nowrap">{{ $course['issue_date'] }}</td>
                    <td colspan="2" class="val center val-nowrap">{{ $course['expiry_date'] }}</td>
                    <td colspan="3" class="val">{{ $course['institute'] }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="12" class="center" style="padding:4px;">No training records</td>
                </tr>
            @endforelse

            <tr class="footer-rev">
                <td colspan="12">FRM-HRA-RMP-032- Rev. 00</td>
            </tr>
        </table>

        @if ($is_pdf ?? false)
            @include('employees.partials.adnoc-cv-page-header')
        @endif

        {{-- PAGE 2 --}}
        <table class="cv">
            <colgroup>
                @for ($i = 0; $i < 12; $i++)
                    <col style="width:8.333%">
                @endfor
            </colgroup>

            <tr><td colspan="12" class="section section-start">SECTION 7 - LAUNGAGES KNOWN</td></tr>
            <tr>
                <td colspan="2" class="col-h">LAUNGAGES</td>
                <td colspan="2" class="col-h">SPOKEN</td>
                <td colspan="2" class="col-h">WRITTEN</td>
                <td colspan="3" class="col-h">UNDERSTOOD</td>
                <td colspan="3" class="col-h">MOTHER TONGUE</td>
            </tr>
            @forelse ($languages as $lang)
                <tr>
                    <td colspan="2" class="val">{{ $lang['name'] }}</td>
                    <td colspan="2" class="val center">{{ $lang['spoken'] }}</td>
                    <td colspan="2" class="val center">{{ $lang['written'] }}</td>
                    <td colspan="3" class="val center">{{ $lang['understood'] }}</td>
                    <td colspan="3" class="val center">{{ $lang['mother_tongue'] }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="12" class="center" style="padding:4px;">No language records</td>
                </tr>
            @endforelse

            <tr><td colspan="12" class="section">SECTION 8 - HEALTH/MEDICAL DATA</td></tr>
            <tr>
                <td colspan="10" class="val">&nbsp;</td>
                <td class="yn-h">YES</td>
                <td class="yn-h">No</td>
            </tr>
            @foreach ($health_questions as $item)
                <tr>
                    <td colspan="10">{{ $item['question'] }}</td>
                    <td class="yn-c">{{ $item['yes'] ? 'X' : '' }}</td>
                    <td class="yn-c">{{ $item['no'] ? 'X' : '' }}</td>
                </tr>
                <tr class="details-row">
                    <td colspan="10">If Yes, details: {{ $item['details'] }}</td>
                    <td class="yn-c">&nbsp;</td>
                    <td class="yn-c">&nbsp;</td>
                </tr>
            @endforeach

            <tr><td colspan="12" class="section">SECTION 9 - GENERAL INFORMATION</td></tr>
            <tr>
                <td colspan="10" class="val">&nbsp;</td>
                <td class="yn-h">YES</td>
                <td class="yn-h">No</td>
            </tr>
            @foreach ($general_questions as $item)
                <tr>
                    <td colspan="10">{{ $item['question'] }}</td>
                    <td class="yn-c">{{ $item['yes'] ? 'X' : '' }}</td>
                    <td class="yn-c">{{ $item['no'] ? 'X' : '' }}</td>
                </tr>
                <tr class="details-row">
                    <td colspan="10">If Yes, Details: {{ $item['details'] }}</td>
                    <td class="yn-c">&nbsp;</td>
                    <td class="yn-c">&nbsp;</td>
                </tr>
            @endforeach

            <tr><td colspan="12" class="section">SECTION 10 - SUMMARY OF WORK EXPERIENCE (START FROM THE CURRENT/LAST VESSEL WORKED)</td></tr>
            @include('employees.partials.adnoc-cv-sea-service-columns')
            @forelse ($sea_services as $index => $svc)
                @if (($is_pdf ?? false) && $index > 0 && $index % 12 === 0)
        </table>
        @include('employees.partials.adnoc-cv-page-header')
        <table class="cv">
            <colgroup>
                @for ($i = 0; $i < 12; $i++)
                    <col style="width:8.333%">
                @endfor
            </colgroup>
            @include('employees.partials.adnoc-cv-sea-service-columns')
                @endif
                <tr class="sea-data-row">
                    <td colspan="2">{{ $svc['vessel_name'] }}</td>
                    <td colspan="1">{{ $svc['vessel_type'] }}</td>
                    <td colspan="1">{{ $svc['rank'] }}</td>
                    <td colspan="1" class="sea-date">{{ $svc['from'] }}</td>
                    <td colspan="1" class="sea-date">{{ $svc['to'] }}</td>
                    <td colspan="1">{{ $svc['months'] }}</td>
                    <td colspan="1">{{ $svc['days'] }}</td>
                    <td colspan="1">{{ $svc['grt'] }}</td>
                    <td colspan="1">{{ $svc['bhp'] }}</td>
                    <td colspan="2" class="sea-company">{{ $svc['company'] }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="12" class="center" style="padding:4px;">No work experience records</td>
                </tr>
            @endforelse
            <tr class="experience-summary">
                <td colspan="3" class="lbl">TOTAL EXPERIENCE IN THE APPLIED RANK (IN YEARS)</td>
                <td colspan="1" class="val center">{{ $experience_rank_years }}</td>
                <td colspan="3" class="lbl">OFFSHORE EXPERIENCE (IN YEARS)</td>
                <td colspan="1" class="val center">{{ $experience_offshore_years }}</td>
                <td colspan="3" class="lbl">DP EXPERIENCE (IN HRS.)</td>
                <td colspan="1" class="val center">{{ $experience_dp_hours }}</td>
            </tr>
        </table>

        @if ($is_pdf ?? false)
            @include('employees.partials.adnoc-cv-page-header', ['closing' => true])
            <table class="cv">
                <colgroup>
                    @for ($i = 0; $i < 12; $i++)
                        <col style="width:8.333%">
                    @endfor
                </colgroup>
                @include('employees.partials.adnoc-cv-closing')
            </table>
        @else
            <table class="cv">
                <colgroup>
                    @for ($i = 0; $i < 12; $i++)
                        <col style="width:8.333%">
                    @endfor
                </colgroup>
                @include('employees.partials.adnoc-cv-closing')
            </table>
        @endif

        </td></tr></table>
    </div>
</body>
</html>
