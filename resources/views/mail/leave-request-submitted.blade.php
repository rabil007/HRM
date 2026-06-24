@extends('mail.layout', ['includeCompanyFooter' => $includeCompanyFooter ?? true])

@section('title', $subjectLine)

@section('content')
    <tr>
        <td class="email-border" style="padding:28px 32px 16px;border-bottom:1px solid #e4e4e7;">
            <p class="email-kicker" style="margin:0 0 8px;font-size:12px;letter-spacing:0.08em;text-transform:uppercase;color:#71717a;">
                {{ $organizationName }}
            </p>
            <h1 class="email-heading" style="margin:0;font-size:20px;line-height:1.4;color:#18181b;">
                New leave request
            </h1>
            <p class="email-muted" style="margin:8px 0 0;font-size:13px;color:#71717a;">
                Pending approval
            </p>
        </td>
    </tr>
    <tr>
        <td style="padding:24px 32px;">
            @if (filled($introMessage))
                <p class="email-text" style="margin:0 0 20px;font-size:15px;line-height:1.6;color:#3f3f46;white-space:pre-wrap;">{{ $introMessage }}</p>
            @else
                <p class="email-text" style="margin:0 0 20px;font-size:15px;line-height:1.6;color:#3f3f46;">
                    A new leave request has been submitted and is pending approval.
                </p>
            @endif

            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="border:1px solid #e4e4e7;border-radius:12px;overflow:hidden;border-collapse:collapse;">
                <tbody>
                    <tr>
                        <td class="email-border" style="padding:12px 16px;width:38%;font-size:13px;font-weight:600;color:#71717a;background-color:#fafafa;border-bottom:1px solid #e4e4e7;">
                            Employee
                        </td>
                        <td class="email-border email-text" style="padding:12px 16px;font-size:14px;color:#18181b;border-bottom:1px solid #e4e4e7;">
                            {{ $employeeName }}@if (filled($employeeNo)) <span style="color:#71717a;">({{ $employeeNo }})</span>@endif
                        </td>
                    </tr>
                    <tr>
                        <td class="email-border" style="padding:12px 16px;font-size:13px;font-weight:600;color:#71717a;background-color:#fafafa;border-bottom:1px solid #e4e4e7;">
                            Department
                        </td>
                        <td class="email-border email-text" style="padding:12px 16px;font-size:14px;color:#18181b;border-bottom:1px solid #e4e4e7;">
                            {{ $departmentName }}
                        </td>
                    </tr>
                    @if (filled($managerName) && $managerName !== '—')
                        <tr>
                            <td class="email-border" style="padding:12px 16px;font-size:13px;font-weight:600;color:#71717a;background-color:#fafafa;border-bottom:1px solid #e4e4e7;">
                                Manager
                            </td>
                            <td class="email-border email-text" style="padding:12px 16px;font-size:14px;color:#18181b;border-bottom:1px solid #e4e4e7;">
                                {{ $managerName }}
                            </td>
                        </tr>
                    @endif
                    <tr>
                        <td class="email-border" style="padding:12px 16px;font-size:13px;font-weight:600;color:#71717a;background-color:#fafafa;border-bottom:1px solid #e4e4e7;">
                            Leave type
                        </td>
                        <td class="email-border email-text" style="padding:12px 16px;font-size:14px;color:#18181b;border-bottom:1px solid #e4e4e7;">
                            @if (filled($leaveTypeColor))
                                <span style="display:inline-block;width:10px;height:10px;border-radius:999px;background-color:{{ $leaveTypeColor }};margin-right:8px;vertical-align:middle;"></span>
                            @endif
                            {{ $leaveType }}
                        </td>
                    </tr>
                    <tr>
                        <td class="email-border" style="padding:12px 16px;font-size:13px;font-weight:600;color:#71717a;background-color:#fafafa;border-bottom:1px solid #e4e4e7;">
                            Dates
                        </td>
                        <td class="email-border email-text" style="padding:12px 16px;font-size:14px;color:#18181b;border-bottom:1px solid #e4e4e7;">
                            {{ $startDate }} to {{ $endDate }}
                            <span style="color:#71717a;">({{ $totalDays }} day{{ $totalDays === '1.0' || $totalDays === '1' ? '' : 's' }})</span>
                        </td>
                    </tr>
                    <tr>
                        <td class="email-border" style="padding:12px 16px;font-size:13px;font-weight:600;color:#71717a;background-color:#fafafa;">
                            Reason
                        </td>
                        <td class="email-border email-text" style="padding:12px 16px;font-size:14px;color:#18181b;white-space:pre-wrap;">
                            {{ $reason }}
                        </td>
                    </tr>
                </tbody>
            </table>

            <table role="presentation" cellspacing="0" cellpadding="0" align="center" style="margin:28px auto 0;">
                <tr>
                    <td class="email-btn-cell" align="center" style="border-radius:12px;background-color:#2563eb;">
                        <a
                            href="{{ $requestUrl }}"
                            class="email-btn-link"
                            style="display:inline-block;padding:14px 28px;font-size:15px;font-weight:700;line-height:1;color:#ffffff;text-decoration:none;border-radius:12px;background-color:#2563eb;border:1px solid #2563eb;"
                        >
                            View leave request
                        </a>
                    </td>
                </tr>
            </table>
        </td>
    </tr>
@endsection
