@extends('mail.layout')

@section('title', $subjectLine)

@section('content')
    <tr>
        <td class="email-border" style="padding:28px 32px 16px;border-bottom:1px solid #e4e4e7;">
            <p class="email-kicker" style="margin:0 0 8px;font-size:12px;letter-spacing:0.08em;text-transform:uppercase;color:#71717a;">
                Email preview
            </p>
            <h1 class="email-heading" style="margin:0;font-size:20px;line-height:1.4;color:#18181b;">
                {{ $subjectLine }}
            </h1>
        </td>
    </tr>
    <tr>
        <td style="padding:24px 32px;">
            <p class="email-text" style="margin:0;font-size:15px;line-height:1.7;color:#3f3f46;white-space:pre-wrap;">{{ $bodyMessage }}</p>
        </td>
    </tr>
@endsection
