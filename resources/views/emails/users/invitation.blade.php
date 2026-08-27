<x-mail::message>
# You've been invited!

**{{ $inviterName }}** has invited you to join **{{ $companyName }}** on our platform.

<x-mail::button :url="$acceptUrl">
Accept Invitation
</x-mail::button>

Please note that this invitation will expire on {{ $expiresAt }}.

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
