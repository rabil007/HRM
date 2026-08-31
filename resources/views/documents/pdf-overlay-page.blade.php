<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        {!! $embedded_font_styles !!}

        @page {
            size: {{ $page_width_mm }}mm {{ $page_height_mm }}mm;
            margin: 0;
        }

        html, body {
            margin: 0;
            padding: 0;
            width: {{ $page_width_mm }}mm;
            height: {{ $page_height_mm }}mm;
            background: transparent;
            position: relative;
            overflow: hidden;
        }

        .overlay-placement {
            position: absolute;
            display: flex;
            align-items: center;
            direction: ltr;
            box-sizing: border-box;
            white-space: nowrap;
            line-height: 1;
            font-family: 'DejaVu Sans', sans-serif;
        }
    </style>
</head>
<body>
    @foreach ($placements as $placement)
        @if ($placement['value'] !== '')
            @if (!empty($placement['is_static_text']))
                <div
                    class="overlay-placement"
                    style="
                        left: {{ $placement['left_mm'] }}mm;
                        top: {{ $placement['top_mm'] }}mm;
                        width: {{ $placement['width_mm'] }}mm;
                        height: {{ $placement['height_mm'] }}mm;
                        font-size: {{ $placement['effective_font_size'] }}pt;
                        font-weight: {{ $placement['font_weight'] }};
                        font-family: {{ $placement['font_family_css'] ?? "'DejaVu Sans', sans-serif" }};
                        color: {{ $placement['font_color'] ?? '#000000' }};
                        text-align: {{ $placement['text_align'] }};
                        white-space: pre-wrap;
                        overflow-wrap: break-word;
                        word-break: normal;
                        line-height: 1.2;
                        overflow: hidden;
                        align-items: flex-start;
                    "
                >
                    <span dir="auto" style="unicode-bidi: plaintext;">{{ $placement['value'] }}</span>
                </div>
            @else
                <div
                    class="overlay-placement"
                    style="
                        left: {{ $placement['left_mm'] }}mm;
                        top: {{ $placement['top_mm'] }}mm;
                        width: {{ $placement['width_mm'] }}mm;
                        height: {{ $placement['height_mm'] }}mm;
                        font-size: {{ $placement['effective_font_size'] }}pt;
                        font-weight: {{ $placement['font_weight'] }};
                        font-family: {{ $placement['font_family_css'] ?? "'DejaVu Sans', sans-serif" }};
                        color: {{ $placement['font_color'] ?? '#000000' }};
                        text-align: {{ $placement['text_align'] }};
                        justify-content: {{ $placement['text_align'] === 'center' ? 'center' : ($placement['text_align'] === 'right' ? 'flex-end' : 'flex-start') }};
                    "
                >
                    <span dir="auto" style="unicode-bidi: plaintext;">{{ $placement['value'] }}</span>
                </div>
            @endif
        @endif
    @endforeach
</body>
</html>
