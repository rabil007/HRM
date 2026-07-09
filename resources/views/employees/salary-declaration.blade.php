<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Employee Declaration and Acknowledgment — {{ $employee_name ?: 'Employee' }}</title>
    <style>
        @if (! empty($embedded_font_styles))
            {!! $embedded_font_styles !!}
        @endif

        :root { --highlight: #4a6079; }

        * { box-sizing: border-box; }

        html, body {
            margin: 0;
            padding: 0;
            background: #4a4a4a;
            font-family: "Times New Roman", Georgia, serif;
            color: #1a1a1a;
        }

        body.pdf-embedded-fonts {
            font-family: 'DejaVu Serif';
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

        .col.en {
            padding-left: 5mm;
            padding-right: 2mm;
        }

        .col.en .field .fill {
            text-align: left;
        }

        .col.ar {
            direction: rtl;
            text-align: right;
            padding-right: 5mm;
            padding-left: 2mm;
        }

        .col.ar .field .fill {
            text-align: right;
        }

        body.pdf-embedded-fonts .col.ar {
            font-family: 'DejaVu Sans';
        }

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

        ol {
            margin: 0 0 18px;
            list-style-position: outside;
            padding-left: 0;
            padding-right: 0;
        }

        ol li { margin-bottom: 9px; }

        .col.en ol {
            padding-left: 2.2em;
            padding-right: 0;
        }

        .col.en ol li {
            padding-left: 0.75em;
            padding-right: 0;
        }

        .col.ar ol {
            padding-right: 2.2em;
            padding-left: 0;
        }

        .col.ar ol li {
            padding-right: 0.75em;
            padding-left: 0;
        }

        .sign-block { margin-top: 26px; }

        .sign-block .field {
            margin-bottom: 10px;
        }

        .sign-block .field--name .fill,
        .sign-block .field--signature .fill,
        .sign-block .field--date .fill {
            border-bottom: none;
        }

        .sign-block .field--signature,
        .sign-block .field--date {
            align-items: center;
        }

        .sign-block .field--signature .fill,
        .sign-block .field--date .fill {
            min-height: 40px;
            padding: 0;
            display: flex;
            align-items: center;
        }

        .sign-block img.signature {
            display: block;
            max-height: 48px;
            max-width: 180px;
            margin-top: 4px;
        }

        .placement-guide {
            display: flex;
            align-items: center;
            justify-content: center;
            width: 100%;
            min-height: 40px;
            border: 1px dashed #94a3b8;
            color: #64748b;
            font: 600 10px/1.2 system-ui, sans-serif;
            letter-spacing: 0.04em;
            text-transform: uppercase;
            background: rgba(148, 163, 184, 0.08);
        }

        .col.ar .placement-guide {
            font-family: 'DejaVu Sans', system-ui, sans-serif;
            text-transform: none;
            letter-spacing: 0;
        }

        @media print {
            html, body { background: #fff; }
            .toolbar { display: none !important; }
            .page { margin: 0; box-shadow: none; width: auto; min-height: auto; padding: 0; }
            .sheet { min-height: auto; }
            @page { size: A4; margin: 14mm; }
        }
    </style>
</head>
<body @class(['pdf-embedded-fonts' => ! empty($embedded_font_styles)])>
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
                        <div class="field field--name"><span class="label">Employee Name:</span><span class="fill">{{ $signed_name ?? $employee_name }}</span></div>
                        <div class="field field--signature"><span class="label">Signature:</span><span class="fill">@if ($show_placement_guides ?? false)<span class="placement-guide">Signature (EN)</span>@elseif (! empty($signature_image_url))<img src="{{ $signature_image_url }}" alt="Signature" class="signature">@endif</span></div>
                        <div class="field field--date"><span class="label">Date:</span><span class="fill">@if ($show_placement_guides ?? false)<span class="placement-guide">Date (EN)</span>@else{{ $signed_date ?? '' }}@endif</span></div>
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
                        <div class="field field--name"><span class="label">اسم الموظف:</span><span class="fill">{{ $signed_name ?? $employee_name }}</span></div>
                        <div class="field field--signature"><span class="label">التوقيع:</span><span class="fill">@if ($show_placement_guides ?? false)<span class="placement-guide">التوقيع (عربي)</span>@elseif (! empty($signature_image_url))<img src="{{ $signature_image_url }}" alt="Signature" class="signature">@endif</span></div>
                        <div class="field field--date"><span class="label">التاريخ:</span><span class="fill">@if ($show_placement_guides ?? false)<span class="placement-guide">التاريخ (عربي)</span>@else{{ $signed_date ?? '' }}@endif</span></div>
                    </div>
                </section>
            </div>
        </div>
    </div>
</body>
</html>
