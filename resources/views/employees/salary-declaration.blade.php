<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Employee Declaration and Acknowledgment — {{ $employee_name ?: 'Employee' }}</title>
    <style>
        :root { --highlight: #4a6079; }

        * { box-sizing: border-box; }

        html, body {
            margin: 0;
            padding: 0;
            background: #4a4a4a;
            font-family: "Times New Roman", Georgia, serif;
            color: #1a1a1a;
        }

        .toolbar {
            position: sticky;
            top: 0;
            z-index: 10;
            display: flex;
            justify-content: center;
            gap: 12px;
            padding: 14px;
            background: #2f2f2f;
        }

        .toolbar button {
            font: 600 14px/1 system-ui, sans-serif;
            padding: 10px 18px;
            border: 0;
            border-radius: 6px;
            background: #2563eb;
            color: #fff;
            cursor: pointer;
        }

        .toolbar button:hover { background: #1d4ed8; }

        .page {
            width: 210mm;
            min-height: 297mm;
            margin: 24px auto;
            background: #fff;
            padding: 22mm 18mm;
            box-shadow: 0 6px 30px rgba(0, 0, 0, 0.4);
        }

        .sheet {
            border: 1px solid #000;
            padding: 14mm 10mm;
            min-height: 245mm;
        }

        .columns {
            display: grid;
            grid-template-columns: 1fr 1px 1fr;
            column-gap: 10mm;
        }

        .divider { background: #000; }

        h1 { font-size: 15px; font-weight: bold; margin: 0 0 16px; }

        .col { font-size: 12.5px; line-height: 1.5; }

        .col.ar { direction: rtl; text-align: right; }

        .intro { font-weight: bold; margin: 0 0 10px; }

        .field {
            display: flex;
            align-items: flex-end;
            gap: 8px;
            margin-bottom: 8px;
        }

        .field .label { white-space: nowrap; }

        .field .fill {
            flex: 1;
            border-bottom: 1px solid #000;
            min-height: 15px;
            font-weight: 600;
            padding: 0 4px 1px;
        }

        .declare-lead { font-weight: bold; margin: 18px 0 10px; }

        ol { margin: 0 0 18px; padding-inline-start: 20px; }

        ol li { margin-bottom: 9px; }

        .ar ol { padding-inline-start: 0; padding-inline-end: 4px; }

        .sign-block { margin-top: 26px; }

        @media print {
            body { background: #fff; }
            .toolbar { display: none !important; }
            .page { margin: 0; box-shadow: none; width: auto; min-height: auto; padding: 0; }
            @page { size: A4; margin: 14mm; }
        }
    </style>
</head>
<body>
    @if ($printable ?? true)
        <div class="toolbar">
            <button type="button" onclick="window.print()">Download / Print PDF</button>
        </div>
    @endif

    <div class="page">
        <div class="sheet">
            <div class="columns">
                {{-- English column --}}
                <section class="col en">
                    <h1>Employee Declaration and Acknowledgment</h1>

                    <p class="intro">I, the undersigned:</p>

                    <div class="field"><span class="label">Name:</span><span class="fill">{{ $employee_name }}</span></div>
                    <div class="field"><span class="label">Nationality:</span><span class="fill">{{ $nationality }}</span></div>
                    <div class="field"><span class="label">EID / Passport No.:</span><span class="fill">{{ $eid_or_passport }}</span></div>
                    <div class="field"><span class="label">Job Title:</span><span class="fill">{{ $job_title }}</span></div>
                    <div class="field"><span class="label">Company Name:</span><span class="fill">{{ $company_name }}</span></div>

                    <p class="declare-lead">I voluntarily declare that:</p>

                    <ol>
                        <li>I agree to receive my salary in accordance with my Employment Contract.</li>
                        <li>I agree that my salary shall be calculated and paid based on the actual days worked during each month, in accordance with the Employment Contract, Company policies, and the applicable laws of the UAE.</li>
                        <li>I understand and accept the salary calculation method and have no objection to payment based on my actual eligible working days.</li>
                        <li>I sign this declaration voluntarily and without coercion.</li>
                    </ol>

                    <div class="sign-block">
                        <div class="field"><span class="label">Employee Name:</span><span class="fill">{{ $employee_name }}</span></div>
                        <div class="field"><span class="label">Signature:</span><span class="fill"></span></div>
                        <div class="field"><span class="label">Date:</span><span class="fill"></span></div>
                    </div>
                </section>

                <div class="divider"></div>

                {{-- Arabic column --}}
                <section class="col ar">
                    <h1>إقرار وموافقة الموظف</h1>

                    <p class="intro">أنا الموقع أدناه:</p>

                    <div class="field"><span class="label">الاسم:</span><span class="fill">{{ $employee_name }}</span></div>
                    <div class="field"><span class="label">الجنسية:</span><span class="fill">{{ $nationality }}</span></div>
                    <div class="field"><span class="label">رقم الهوية الإماراتية / جواز السفر:</span><span class="fill">{{ $eid_or_passport }}</span></div>
                    <div class="field"><span class="label">المسمى الوظيفي:</span><span class="fill">{{ $job_title }}</span></div>
                    <div class="field"><span class="label">اسم الشركة:</span><span class="fill">{{ $company_name }}</span></div>

                    <p class="declare-lead">أقر وأتعهد بكامل إرادتي واختياري بما يلي:</p>

                    <ol>
                        <li>أنني أوافق على استلام راتبي الشهري وفقاً لما هو متفق عليه في عقد العمل المبرم بيني وبين الشركة.</li>
                        <li>أنني أوافق على أن يتم احتساب وصرف الراتب بناءً على أيام العمل الفعلية التي قمت بأدائها خلال كل شهر، وذلك وفقاً لعقد العمل وسياسات الشركة والأنظمة والقوانين المعمول بها في دولة الإمارات العربية المتحدة.</li>
                        <li>أؤكد أنني على علم بطريقة احتساب الراتب، وأنني لا أعترض على صرفه وفقاً لأيام العمل الفعلية المستحقة.</li>
                        <li>تم توقيع هذا الإقرار بإرادتي الحرة ودون أي إكراه، ويعتبر جزءاً مكملاً للتفاهم القائم بيني وبين الشركة.</li>
                    </ol>

                    <div class="sign-block">
                        <div class="field"><span class="label">اسم الموظف:</span><span class="fill">{{ $employee_name }}</span></div>
                        <div class="field"><span class="label">التوقيع:</span><span class="fill"></span></div>
                        <div class="field"><span class="label">التاريخ:</span><span class="fill">___ / ___ / ___</span></div>
                    </div>
                </section>
            </div>
        </div>
    </div>
</body>
</html>
