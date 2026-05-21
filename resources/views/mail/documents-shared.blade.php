@extends('mail.layout')

@section('title', $subjectLine)

@section('content')
    <tr>
        <td style="padding:28px 32px 16px;border-bottom:1px solid #e4e4e7;">
            <p style="margin:0 0 8px;font-size:12px;letter-spacing:0.08em;text-transform:uppercase;color:#71717a;">
                {{ $organizationName }}
            </p>
            <h1 style="margin:0;font-size:20px;line-height:1.4;color:#18181b;">
                Documents from {{ $senderName }}
            </h1>
        </td>
    </tr>
    <tr>
        <td style="padding:24px 32px;">
            @if ($bodyMessage)
                <p style="margin:0 0 20px;font-size:15px;line-height:1.6;color:#3f3f46;white-space:pre-wrap;">{{ $bodyMessage }}</p>
            @else
                <p style="margin:0 0 20px;font-size:15px;line-height:1.6;color:#3f3f46;">
                    Please find the attached employee documents below.
                </p>
            @endif

            <p style="margin:0 0 12px;font-size:13px;font-weight:600;color:#18181b;">
                Attachments ({{ count($attachmentSummaries) }})
            </p>
            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="border:1px solid #e4e4e7;border-radius:8px;overflow:hidden;">
                @foreach ($attachmentSummaries as $attachment)
                    <tr>
                        <td style="padding:12px 16px;border-bottom:1px solid #f4f4f5;font-size:14px;color:#3f3f46;">
                            {{ $attachment['name'] }}
                            <span style="color:#71717a;">
                                — {{ number_format($attachment['size_bytes'] / 1024 / 1024, 1) }} MB
                            </span>
                        </td>
                    </tr>
                @endforeach
            </table>
        </td>
    </tr>
@endsection
