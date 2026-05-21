@extends('mail.layout')

@section('title', 'SMTP test')

@section('content')
    <tr>
        <td style="padding:28px 32px;">
            <h1 style="margin:0 0 16px;font-size:20px;line-height:1.4;color:#18181b;">
                SMTP test
            </h1>
            <p style="margin:0;font-size:15px;line-height:1.6;color:#3f3f46;white-space:pre-wrap;">{{ $body }}</p>
        </td>
    </tr>
@endsection
