@extends('mail.layout', ['includeCompanyFooter' => $includeCompanyFooter ?? true])

@section('title', 'Crew Operations requires attention')

@section('content')
    <tr>
        <td style="padding:28px 32px 16px;border-bottom:1px solid #e4e4e7;">
            <p style="margin:0 0 8px;font-size:12px;letter-spacing:0.08em;text-transform:uppercase;color:#71717a;">
                {{ $organizationName }}
            </p>
            <h1 style="margin:0;font-size:20px;line-height:1.4;color:#18181b;">
                Crew Operations requires attention
            </h1>
            <p style="margin:8px 0 0;font-size:13px;color:#71717a;">
                Severity indicator: {{ strtoupper($severityLabel) }}
            </p>
        </td>
    </tr>
    <tr>
        <td style="padding:24px 32px;">
            <p style="margin:0 0 16px;font-size:15px;line-height:1.6;color:#3f3f46;">
                Crew Operations has an item that needs review in OMS-HRM.
                Open the application to see the details you are authorized to view.
            </p>
            <p style="margin:0 0 20px;font-size:13px;line-height:1.5;color:#71717a;">
                This message does not include employee, vessel, or assignment details for privacy.
            </p>

            @if (filled($ctaUrl))
                <table role="presentation" cellspacing="0" cellpadding="0" style="margin:0;">
                    <tr>
                        <td style="border-radius:8px;background-color:#18181b;">
                            <a href="{{ $ctaUrl }}"
                               style="display:inline-block;padding:12px 20px;font-size:14px;font-weight:600;color:#ffffff;text-decoration:none;">
                                Open OMS-HRM
                            </a>
                        </td>
                    </tr>
                </table>
            @endif
        </td>
    </tr>
@endsection
