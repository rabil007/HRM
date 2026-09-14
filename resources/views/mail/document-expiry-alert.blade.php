@extends('mail.layout', ['includeCompanyFooter' => $includeCompanyFooter ?? true])

@section('title', 'Employee Document Expiry Alert')

@section('content')
    <tr>
        <td style="padding:28px 32px 16px;border-bottom:1px solid #e4e4e7;">
            <p style="margin:0 0 8px;font-size:12px;letter-spacing:0.08em;text-transform:uppercase;color:#71717a;">
                Document compliance
            </p>
            <h1 style="margin:0;font-size:20px;line-height:1.4;color:#18181b;">
                Employee Document Expiry Alert
            </h1>
            <p style="margin:8px 0 0;font-size:13px;color:#71717a;">
                {{ $organizationName }}
            </p>
        </td>
    </tr>
    <tr>
        <td style="padding:24px 32px;">
            <p style="margin:0 0 16px;font-size:15px;line-height:1.6;color:#3f3f46;">
                The following employee documents will expire within the next {{ $alertWindowDays }} days and require attention.
            </p>

            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="border:1px solid #e4e4e7;border-radius:8px;overflow:hidden;border-collapse:collapse;">
                <thead>
                    <tr style="background-color:#f4f4f5;">
                        <th align="left" style="padding:12px 16px;font-size:12px;font-weight:600;color:#18181b;border-bottom:1px solid #e4e4e7;">Employee</th>
                        <th align="left" style="padding:12px 16px;font-size:12px;font-weight:600;color:#18181b;border-bottom:1px solid #e4e4e7;">Employee No.</th>
                        <th align="left" style="padding:12px 16px;font-size:12px;font-weight:600;color:#18181b;border-bottom:1px solid #e4e4e7;">Document</th>
                        <th align="left" style="padding:12px 16px;font-size:12px;font-weight:600;color:#18181b;border-bottom:1px solid #e4e4e7;">Expiry</th>
                        <th align="left" style="padding:12px 16px;font-size:12px;font-weight:600;color:#18181b;border-bottom:1px solid #e4e4e7;">Days Remaining</th>
                        <th align="left" style="padding:12px 16px;font-size:12px;font-weight:600;color:#18181b;border-bottom:1px solid #e4e4e7;">Action</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($rows as $row)
                        <tr>
                            <td style="padding:12px 16px;font-size:14px;color:#3f3f46;border-bottom:1px solid #f4f4f5;">{{ $row['employee_name'] }}</td>
                            <td style="padding:12px 16px;font-size:14px;color:#3f3f46;border-bottom:1px solid #f4f4f5;">{{ $row['employee_id'] }}</td>
                            <td style="padding:12px 16px;font-size:14px;color:#3f3f46;border-bottom:1px solid #f4f4f5;">{{ $row['document_name'] }}</td>
                            <td style="padding:12px 16px;font-size:14px;color:#3f3f46;border-bottom:1px solid #f4f4f5;">{{ $row['expiry_date'] }}</td>
                            <td style="padding:12px 16px;font-size:14px;color:#3f3f46;border-bottom:1px solid #f4f4f5;">{{ $row['days_remaining'] }}</td>
                            <td style="padding:12px 16px;font-size:14px;color:#3f3f46;border-bottom:1px solid #f4f4f5;white-space:nowrap;">
                                @if (! empty($row['folder_url']))
                                    <a
                                        href="{{ $row['folder_url'] }}"
                                        style="display:inline-block;padding:6px 12px;font-size:12px;font-weight:600;line-height:1;color:#ffffff;text-decoration:none;border-radius:6px;background-color:#2563eb;border:1px solid #2563eb;"
                                    >View</a>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>

            @if (! empty($complianceUrl))
                <table role="presentation" cellspacing="0" cellpadding="0" align="center" style="margin:24px auto 0;">
                    <tr>
                        <td align="center" style="border-radius:12px;background-color:#2563eb;">
                            <a href="{{ $complianceUrl }}" style="display:inline-block;padding:14px 32px;font-size:15px;font-weight:700;line-height:1;color:#ffffff;text-decoration:none;border-radius:12px;background-color:#2563eb;border:1px solid #2563eb;">
                                View Document Compliance
                            </a>
                        </td>
                    </tr>
                </table>
            @endif

            <p style="margin:20px 0 0;font-size:14px;line-height:1.6;color:#3f3f46;">
                Please review and renew the required documents before expiry.
            </p>
            <p style="margin:12px 0 0;font-size:12px;line-height:1.6;color:#a1a1aa;">
                This is an automated compliance notification from OMS-HRM.
            </p>
        </td>
    </tr>
@endsection
