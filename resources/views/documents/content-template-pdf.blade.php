<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ $title }}</title>
    <style>
        {!! $embedded_font_styles !!}

        @page {
            size: A4 portrait;
            margin: 20mm 18mm 20mm 18mm;
        }

        html, body {
            margin: 0;
            padding: 0;
            background-color: #ffffff;
            color: #111827;
            font-family: 'DejaVu Sans', 'Helvetica Neue', Arial, sans-serif;
            font-size: 11pt;
            line-height: 1.6;
            -webkit-print-color-adjust: exact;
            print-color-adjust: exact;
        }

        .document-container {
            width: 100%;
            box-sizing: border-box;
        }

        .document-body {
            white-space: pre-wrap;
            word-wrap: break-word;
            overflow-wrap: break-word;
        }

        .document-header {
            margin-bottom: 24px;
            padding-bottom: 12px;
            border-bottom: 1px solid #e5e7eb;
        }

        .document-title {
            font-size: 16pt;
            font-weight: bold;
            color: #111827;
            margin: 0 0 6px 0;
        }

        .document-meta {
            font-size: 9pt;
            color: #6b7280;
        }
    </style>
</head>
<body>
    <div class="document-container">
        @if (!empty($show_header))
            <div class="document-header">
                <h1 class="document-title">{{ $title }}</h1>
                <div class="document-meta">{{ $company_name }} &bull; {{ $date }}</div>
            </div>
        @endif

        <div class="document-body">{{ $content }}</div>
    </div>
</body>
</html>
