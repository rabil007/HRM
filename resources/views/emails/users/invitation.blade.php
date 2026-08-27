<x-mail::message>
# You've been invited!

**{{ $inviterName }}** has invited you to join **{{ $companyName }}** on {{ config('app.name') }}.

<x-mail::button :url="$acceptUrl">
Accept Invitation
</x-mail::button>

If you already have an account on this platform, click the button above and sign in with your existing credentials to accept access. If you are new to the platform, you will be guided through initial account setup and password creation.

Please note that this invitation link will expire on {{ $expiresAt }}.

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
