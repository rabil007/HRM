@php
    $brandName = $mailBranding['brand_name'] ?? config('app.name');
    $greeting = filled($userName ?? null) ? "Hello {$userName}," : 'Hello,';
@endphp

@extends('mail.layout')

@section('title', "Reset your password — {$brandName}")

@section('content')
    <tr>
        <td class="email-border" style="padding:28px 32px 20px;border-bottom:1px solid #e4e4e7;text-align:center;">
            @if (filled($mailBranding['logo_src'] ?? null))
                <img
                    src="{{ $mailBranding['logo_src'] }}"
                    alt="{{ $brandName }}"
                    width="160"
                    style="display:block;width:160px;max-width:160px;height:auto;margin:0 auto 16px;border:0;"
                />
            @else
                <p class="email-heading" style="margin:0 0 8px;font-size:22px;font-weight:700;line-height:1.2;color:#18181b;">
                    {{ $brandName }}
                </p>
            @endif
            <p class="email-kicker" style="margin:0;font-size:12px;letter-spacing:0.1em;text-transform:uppercase;color:#71717a;">
                Password reset
            </p>
        </td>
    </tr>
    <tr>
        <td style="padding:28px 32px 8px;">
            <p class="email-text" style="margin:0 0 16px;font-size:16px;line-height:1.5;color:#18181b;font-weight:600;">
                {{ $greeting }}
            </p>
            <p class="email-text" style="margin:0 0 20px;font-size:15px;line-height:1.7;color:#3f3f46;">
                We received a request to reset the password for your account. Use the button below to choose a new password.
            </p>

            <table role="presentation" cellspacing="0" cellpadding="0" align="center" style="margin:0 auto 24px;">
                <tr>
                    <td class="email-btn-cell" align="center" style="border-radius:12px;background-color:#2563eb;">
                        <a
                            href="{{ $url }}"
                            class="email-btn-link"
                            style="display:inline-block;padding:14px 32px;font-size:15px;font-weight:700;line-height:1;color:#ffffff;text-decoration:none;border-radius:12px;background-color:#2563eb;border:1px solid #2563eb;"
                        >
                            Reset password
                        </a>
                    </td>
                </tr>
            </table>

            <p class="email-muted" style="margin:0 0 12px;font-size:14px;line-height:1.6;color:#71717a;text-align:center;">
                This link expires in <strong style="color:inherit;">{{ $expireMinutes }} minutes</strong>.
            </p>
            <p class="email-muted" style="margin:0;font-size:14px;line-height:1.6;color:#71717a;text-align:center;">
                If you did not request a password reset, you can safely ignore this email.
            </p>
        </td>
    </tr>
    <tr>
        <td class="email-border" style="padding:20px 32px 28px;border-top:1px solid #e4e4e7;">
            <p class="email-muted" style="margin:0 0 10px;font-size:12px;line-height:1.6;color:#71717a;">
                If the button does not work, copy and paste this link into your browser:
            </p>
            <p class="email-url-box" style="margin:0;padding:14px 16px;border:1px solid #e4e4e7;border-radius:10px;background-color:#fafafa;word-break:break-all;">
                <a href="{{ $url }}" class="email-url-link" style="font-size:12px;line-height:1.6;color:#2563eb;text-decoration:underline;">
                    {{ $url }}
                </a>
            </p>
        </td>
    </tr>
@endsection
