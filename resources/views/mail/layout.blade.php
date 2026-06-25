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
    </style>
</head>
<body class="email-body" style="margin:0;padding:0;background-color:#f4f4f5;font-family:Arial,Helvetica,sans-serif;color:#18181b;">
<table role="presentation" class="email-body" width="100%" cellspacing="0" cellpadding="0" style="background-color:#f4f4f5;padding:32px 16px;">
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
