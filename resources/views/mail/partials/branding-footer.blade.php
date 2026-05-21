@php($b = $mailBranding ?? [])
<tr>
    <td style="padding:0;background-color:#1e2930;">
        <table role="presentation" width="100%" cellspacing="0" cellpadding="0">
            <tr>
                <td style="padding:24px 20px 24px 28px;vertical-align:top;width:220px;">
                    @if (filled($b['logo_src'] ?? null))
                        <table role="presentation" cellspacing="0" cellpadding="0" style="background-color:#ffffff;border-radius:6px;">
                            <tr>
                                <td style="padding:12px 14px;">
                                    <img
                                        src="{{ $b['logo_src'] }}"
                                        alt="{{ $b['brand_name'] ?? config('app.name') }}"
                                        width="192"
                                        style="display:block;width:192px;max-width:192px;height:auto;border:0;"
                                    />
                                </td>
                            </tr>
                        </table>
                    @else
                        <p style="margin:0;font-size:22px;font-weight:700;color:#3b82f6;line-height:1.2;">
                            {{ $b['brand_name'] ?? config('app.name') }}
                        </p>
                        @if (filled($b['tagline'] ?? null))
                            <p style="margin:8px 0 0;font-size:11px;color:#94a3b8;line-height:1.4;">
                                {{ $b['tagline'] }}
                            </p>
                        @endif
                    @endif
                </td>
                <td style="padding:24px 28px 24px 0;vertical-align:top;">
                    <p style="margin:0 0 4px;font-size:18px;font-weight:700;color:#60a5fa;line-height:1.3;">
                        {{ filled($b['company_name'] ?? null) ? $b['company_name'] : ($b['brand_name'] ?? config('app.name')) }}
                    </p>
                    @if (filled($b['tagline'] ?? null) && filled($b['logo_src'] ?? null))
                        <p style="margin:0 0 12px;font-size:12px;color:#94a3b8;line-height:1.4;">
                            {{ $b['tagline'] }}
                        </p>
                    @endif
                    @if (filled($b['support_phone'] ?? null))
                        <p style="margin:0 0 8px;font-size:13px;color:#f8fafc;line-height:1.5;">
                            {{ $b['support_phone'] }}
                        </p>
                    @endif
                    @if (filled($b['website'] ?? null) || filled($b['support_email'] ?? null))
                        <p style="margin:0 0 8px;font-size:13px;line-height:1.5;">
                            @if (filled($b['website_url'] ?? null))
                                <a href="{{ $b['website_url'] }}" style="color:#60a5fa;text-decoration:underline;">{{ $b['website'] }}</a>
                            @elseif (filled($b['website'] ?? null))
                                <span style="color:#60a5fa;">{{ $b['website'] }}</span>
                            @endif
                            @if (filled($b['website'] ?? null) && filled($b['support_email'] ?? null))
                                <span style="color:#64748b;"> | </span>
                            @endif
                            @if (filled($b['support_email'] ?? null))
                                <a href="mailto:{{ $b['support_email'] }}" style="color:#60a5fa;text-decoration:underline;">{{ $b['support_email'] }}</a>
                            @endif
                        </p>
                    @endif
                    @if (filled($b['company_address'] ?? null))
                        <p style="margin:0;font-size:13px;color:#f8fafc;line-height:1.5;">
                            {{ $b['company_address'] }}
                        </p>
                    @endif
                </td>
            </tr>
            @if (filled($b['certifications'] ?? null))
                <tr>
                    <td colspan="2" style="padding:12px 28px;background-color:#2563eb;">
                        <p style="margin:0;font-size:12px;font-weight:600;color:#ffffff;text-align:center;letter-spacing:0.02em;line-height:1.4;">
                            {{ $b['certifications'] }}
                        </p>
                    </td>
                </tr>
            @endif
        </table>
    </td>
</tr>
