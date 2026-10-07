@extends('mail.layout', ['includeCompanyFooter' => $includeCompanyFooter ?? true])

@section('title', $subjectLine)

@section('content')
    <tr>
        <td class="email-border email-section" style="padding:28px 32px 16px;border-bottom:1px solid #e4e4e7;">
            <p class="email-kicker" style="margin:0 0 8px;font-size:12px;letter-spacing:0.08em;text-transform:uppercase;color:#71717a;">
                {{ $organizationName }}
            </p>
            <h1 class="email-heading" style="margin:0;font-size:20px;line-height:1.4;color:#18181b;">
                Requirement returned for changes
            </h1>
            <p class="email-muted" style="margin:8px 0 0;font-size:13px;color:#71717a;">
                {{ $requirementNumber }}
            </p>
        </td>
    </tr>
    <tr>
        <td class="email-section" style="padding:24px 32px;">
            <p class="email-text" style="margin:0 0 20px;font-size:15px;line-height:1.6;color:#3f3f46;white-space:pre-wrap;">
                {{ $introMessage ?: ($recruiterName.' returned requirement '.$requirementNumber.' for corrections.') }}
            </p>

            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="border:1px solid #e4e4e7;border-radius:12px;overflow:hidden;border-collapse:collapse;">
                <tbody>
                    <tr class="email-detail-row">
                        <td class="email-border email-detail-label" style="padding:12px 16px;width:38%;font-size:13px;font-weight:600;color:#71717a;background-color:#fafafa;border-bottom:1px solid #e4e4e7;">
                            Return reason
                        </td>
                        <td class="email-border email-text email-detail-value" style="padding:12px 16px;font-size:14px;color:#18181b;border-bottom:1px solid #e4e4e7;white-space:pre-wrap;">
                            {{ $returnReason }}
                        </td>
                    </tr>
                    @foreach ($details as $detail)
                        <tr class="email-detail-row">
                            <td class="email-border email-detail-label" style="padding:12px 16px;width:38%;font-size:13px;font-weight:600;color:#71717a;background-color:#fafafa;border-bottom:1px solid #e4e4e7;">
                                {{ $detail['label'] }}
                            </td>
                            <td class="email-border email-text email-detail-value" style="padding:12px 16px;font-size:14px;color:#18181b;border-bottom:1px solid #e4e4e7;">
                                {{ $detail['value'] }}
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>

            <p style="margin:24px 0 0;">
                <a href="{{ $requirementUrl }}" class="email-button" style="display:inline-block;padding:12px 18px;border-radius:10px;background-color:#18181b;color:#ffffff;font-size:14px;font-weight:600;text-decoration:none;">
                    Update requirement
                </a>
            </p>
        </td>
    </tr>
@endsection
