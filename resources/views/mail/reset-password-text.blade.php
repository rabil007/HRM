@php
    $brandName = $mailBranding['brand_name'] ?? config('app.name');
    $greeting = filled($userName ?? null) ? "Hello {$userName}," : 'Hello,';
@endphp
{{ $brandName }} — Password reset

@if (isset($body) && filled($body))
{{ $body }}
@else
{{ $greeting }}

We received a request to reset the password for your account.

Reset your password using this link:
{{ $url }}

This link expires in {{ $expireMinutes }} minutes.

If you did not request a password reset, you can safely ignore this email.
@endif
