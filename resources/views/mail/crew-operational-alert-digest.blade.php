@extends('mail.layout', ['includeCompanyFooter' => $includeCompanyFooter ?? true])

@section('title', $subjectLine ?? 'Crew Operations Alert Summary')

@section('content')
    <tr>
        <td style="padding:28px 32px 16px;border-bottom:1px solid #e4e4e7;">
            <p style="margin:0 0 8px;font-size:12px;letter-spacing:0.08em;text-transform:uppercase;color:#71717a;font-weight:600;">
                {{ $organizationName }}
            </p>
            <h1 style="margin:0;font-size:20px;line-height:1.4;color:#18181b;">
                {{ $subjectLine ?? 'Crew Operations Alert Summary' }}
            </h1>
        </td>
    </tr>
    <tr>
        <td style="padding:24px 32px;">
            {!! $bodyHtml !!}
        </td>
    </tr>
@endsection
