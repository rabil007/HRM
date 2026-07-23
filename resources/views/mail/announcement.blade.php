@extends('mail.layout', ['includeCompanyFooter' => true])

@section('title', $title)

@section('content')
    <tr>
        <td class="email-border" style="padding:28px 32px 16px;border-bottom:1px solid #e4e4e7;">
            <p class="email-kicker" style="margin:0 0 8px;font-size:12px;letter-spacing:0.08em;text-transform:uppercase;color:#71717a;">
                {{ $companyName }}
            </p>
            <h1 class="email-heading" style="margin:0;font-size:20px;line-height:1.4;color:#18181b;">
                {{ $title }}
            </h1>
            <p class="email-muted" style="margin:8px 0 0;font-size:13px;color:#71717a;">
                Priority: {{ $priority }} · Published {{ $publishedAt }}
            </p>
        </td>
    </tr>
    <tr>
        <td style="padding:24px 32px;">
            <div class="email-text" style="font-size:15px;line-height:1.6;color:#3f3f46;">
                {!! $bodyHtml !!}
            </div>

            @if (count($attachmentLinks) > 0)
                <p style="margin:24px 0 8px;font-size:13px;font-weight:600;color:#71717a;text-transform:uppercase;letter-spacing:0.06em;">
                    Attachments
                </p>
                <ul style="margin:0;padding-left:18px;color:#3f3f46;font-size:14px;line-height:1.7;">
                    @foreach ($attachmentLinks as $attachment)
                        <li>
                            <a href="{{ $attachment['url'] }}" style="color:#2563eb;text-decoration:underline;">
                                {{ $attachment['name'] }}
                            </a>
                        </li>
                    @endforeach
                </ul>
            @endif
        </td>
    </tr>
@endsection
