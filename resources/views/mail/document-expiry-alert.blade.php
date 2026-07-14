@extends('mail.layout', ['includeCompanyFooter' => $includeCompanyFooter ?? true])

@section('title', "Document Expiry Alert - Next {$alertWindowDays} Days")

@section('content')
    <tr>
        <td style="padding:28px 32px 16px;border-bottom:1px solid #e4e4e7;">
            <p style="margin:0 0 8px;font-size:12px;letter-spacing:0.08em;text-transform:uppercase;color:#71717a;">
                {{ $organizationName }}
            </p>
            <h1 style="margin:0;font-size:20px;line-height:1.4;color:#18181b;">
                Document Expiry Alert
            </h1>
            <p style="margin:8px 0 0;font-size:13px;color:#71717a;">
                Documents expiring within the next {{ $alertWindowDays }} days
            </p>
        </td>
    </tr>
    <tr>
        <td style="padding:24px 32px;">
            <p style="margin:0 0 16px;font-size:15px;line-height:1.6;color:#3f3f46;">
                The following employee documents require attention before they expire.
            </p>

            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="border:1px solid #e4e4e7;border-radius:8px;overflow:hidden;border-collapse:collapse;">
                <thead>
                    <tr style="background-color:#f4f4f5;">
                        <th align="left" style="padding:12px 16px;font-size:12px;font-weight:600;color:#18181b;border-bottom:1px solid #e4e4e7;">Employee Name</th>
                        <th align="left" style="padding:12px 16px;font-size:12px;font-weight:600;color:#18181b;border-bottom:1px solid #e4e4e7;">Employee ID</th>
                        <th align="left" style="padding:12px 16px;font-size:12px;font-weight:600;color:#18181b;border-bottom:1px solid #e4e4e7;">Document Name</th>
                        <th align="left" style="padding:12px 16px;font-size:12px;font-weight:600;color:#18181b;border-bottom:1px solid #e4e4e7;">Expiry Date</th>
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
        </td>
    </tr>
@endsection
