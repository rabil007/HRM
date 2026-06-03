@extends('mail.layout')

@section('title', $subjectLine)

@section('content')
    <tr>
        <td style="padding:28px 32px 16px;border-bottom:1px solid #e4e4e7;">
            <p style="margin:0 0 8px;font-size:12px;letter-spacing:0.08em;text-transform:uppercase;color:#71717a;">
                {{ $organizationName }}
            </p>
            <h1 style="margin:0;font-size:20px;line-height:1.4;color:#18181b;">
                Document expiry alert
            </h1>
            <p style="margin:8px 0 0;font-size:13px;color:#71717a;">
                Documents expiring within {{ $alertWindowDays }} days
            </p>
        </td>
    </tr>
    <tr>
        <td style="padding:24px 32px;">
            @if ($introMessage !== '')
                <p style="margin:0 0 20px;font-size:15px;line-height:1.6;color:#3f3f46;white-space:pre-wrap;">{{ $introMessage }}</p>
            @endif

            @foreach ($employeeGroups as $group)
                <div style="margin-bottom:24px;">
                    <p style="margin:0 0 10px;font-size:15px;font-weight:600;color:#18181b;">
                        {{ $group['employee_name'] }}
                    </p>
                    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="border:1px solid #e4e4e7;border-radius:8px;overflow:hidden;">
                        @foreach ($group['documents'] as $document)
                            <tr>
                                <td style="padding:12px 16px;border-bottom:1px solid #f4f4f5;font-size:14px;color:#3f3f46;">
                                    <span style="font-weight:500;color:#18181b;">{{ $document['document_type'] }}</span>
                                    — {{ $document['document_name'] }}
                                    <br>
                                    <span style="font-size:13px;color:#71717a;">
                                        Expires {{ $document['expiry_date'] }}
                                        ({{ $document['remaining_days'] }} {{ $document['remaining_days'] === 1 ? 'day' : 'days' }} remaining)
                                    </span>
                                </td>
                            </tr>
                        @endforeach
                    </table>
                </div>
            @endforeach
        </td>
    </tr>
@endsection
