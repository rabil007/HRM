@extends('mail.layout')

@section('title', 'Queue job failed')

@section('content')
    <tr>
        <td style="padding:28px 32px;">
            <h1 style="margin:0 0 16px;font-size:20px;line-height:1.4;color:#18181b;">
                Queue job failed
            </h1>
            <p style="margin:0 0 16px;font-size:15px;line-height:1.6;color:#3f3f46;">
                A background job failed and needs attention.
            </p>
            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="margin:0 0 20px;border:1px solid #e4e4e7;border-radius:12px;overflow:hidden;">
                <tr>
                    <td style="padding:12px 16px;background-color:#fafafa;font-size:13px;color:#71717a;width:140px;">Job</td>
                    <td style="padding:12px 16px;font-size:14px;color:#18181b;font-weight:600;">{{ $jobName }}</td>
                </tr>
                <tr>
                    <td style="padding:12px 16px;background-color:#fafafa;font-size:13px;color:#71717a;border-top:1px solid #e4e4e7;">Queue</td>
                    <td style="padding:12px 16px;font-size:14px;color:#18181b;border-top:1px solid #e4e4e7;">{{ $queueName }} ({{ $queueConnection }})</td>
                </tr>
                @if (filled($jobUuid))
                    <tr>
                        <td style="padding:12px 16px;background-color:#fafafa;font-size:13px;color:#71717a;border-top:1px solid #e4e4e7;">UUID</td>
                        <td style="padding:12px 16px;font-size:13px;color:#18181b;border-top:1px solid #e4e4e7;font-family:Menlo,Monaco,Consolas,monospace;">{{ $jobUuid }}</td>
                    </tr>
                @endif
                <tr>
                    <td style="padding:12px 16px;background-color:#fafafa;font-size:13px;color:#71717a;border-top:1px solid #e4e4e7;">Error</td>
                    <td style="padding:12px 16px;font-size:14px;color:#b91c1c;border-top:1px solid #e4e4e7;">{{ $exceptionSummary }}</td>
                </tr>
            </table>
            <p style="margin:0 0 8px;font-size:13px;line-height:1.5;color:#71717a;font-weight:600;">Exception</p>
            <pre style="margin:0;padding:16px;background-color:#18181b;color:#f4f4f5;border-radius:12px;font-size:12px;line-height:1.5;white-space:pre-wrap;word-break:break-word;overflow-wrap:anywhere;">{{ $exceptionDetails }}</pre>
        </td>
    </tr>
@endsection
