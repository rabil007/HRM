<!DOCTYPE html>
<html lang="en" xmlns:v="urn:schemas-microsoft-com:vml" xmlns:o="urn:schemas-microsoft-com:office:office">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="color-scheme" content="light">
    <meta name="supported-color-schemes" content="light">
    <title>@yield('title', $mailBranding['brand_name'] ?? config('app.name'))</title>
    <!--[if mso]>
    <noscript>
        <xml>
            <o:OfficeDocumentSettings>
                <o:PixelsPerInch>96</o:PixelsPerInch>
            </o:OfficeDocumentSettings>
        </xml>
    </noscript>
    <![endif]-->
    <style>
        :root {
            color-scheme: light;
        }

        body,
        table,
        td,
        p,
        a {
            -webkit-text-size-adjust: 100%;
            -ms-text-size-adjust: 100%;
        }

        img {
            max-width: 100% !important;
            height: auto !important;
        }

        /* Mobile: tighten padding, stack key/value rows, full-width CTAs.
           Classes are opt-in on content blades; clients that strip <style> keep desktop table layout. */
        @media only screen and (max-width: 620px) {
            .email-shell {
                padding: 12px 8px !important;
            }

            .email-card {
                width: 100% !important;
                border-radius: 12px !important;
            }

            .email-section {
                padding-left: 16px !important;
                padding-right: 16px !important;
            }

            .email-heading {
                font-size: 18px !important;
                line-height: 1.35 !important;
            }

            .email-text,
            .email-detail-value {
                word-break: break-word !important;
                overflow-wrap: anywhere !important;
            }

            .email-detail-row,
            .email-detail-label,
            .email-detail-value {
                display: block !important;
                width: 100% !important;
                max-width: 100% !important;
                box-sizing: border-box !important;
            }

            .email-detail-label {
                border-bottom: none !important;
                padding-bottom: 2px !important;
            }

            .email-detail-value {
                padding-top: 2px !important;
            }

            .email-btn-cell,
            .email-btn-link,
            .email-button {
                display: block !important;
                width: 100% !important;
                box-sizing: border-box !important;
                text-align: center !important;
            }

            .email-footer-col {
                display: block !important;
                width: 100% !important;
                max-width: 100% !important;
                padding-left: 20px !important;
                padding-right: 20px !important;
                box-sizing: border-box !important;
            }

            .email-footer-logo {
                width: 160px !important;
                max-width: 160px !important;
            }

            .email-table-scroll {
                display: block !important;
                width: 100% !important;
                overflow-x: auto !important;
                -webkit-overflow-scrolling: touch !important;
            }
        }
    </style>
</head>
<body class="email-body" style="margin:0;padding:0;background-color:#f4f4f5;font-family:Arial,Helvetica,sans-serif;color:#18181b;">
<table role="presentation" class="email-body email-shell" width="100%" cellspacing="0" cellpadding="0" style="background-color:#f4f4f5;padding:32px 16px;">
    <tr>
        <td align="center">
            <table role="presentation" class="email-card" width="100%" cellspacing="0" cellpadding="0" style="max-width:640px;background-color:#ffffff;border:1px solid #e4e4e7;border-radius:16px;overflow:hidden;">
                @yield('content')
                @if ($includeCompanyFooter ?? true)
                    @include('mail.partials.branding-footer')
                @endif
            </table>
            @if ($includeCompanyFooter ?? true)
            <p class="email-footer-copy" style="margin:16px 0 0;font-size:12px;line-height:1.5;color:#a1a1aa;text-align:center;">
                &copy; {{ now()->year }} {{ $mailBranding['brand_name'] ?? config('app.name') }}. All rights reserved.
            </p>
            @endif
        </td>
    </tr>
</table>
</body>
</html>
