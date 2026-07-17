<p>Hello,</p>

<p>
    Your crew movement correction for assignment
    <strong>{{ $assignmentNo }}</strong>
    ({{ $employeeName }}) — {{ $phaseLabel }} — was
    <strong>{{ $status }}</strong>.
</p>

<p><strong>Request reason:</strong> {{ $reason }}</p>

@if ($decisionNotes)
    <p><strong>Decision notes:</strong> {{ $decisionNotes }}</p>
@endif

<p>
    <a href="{{ $correctionUrl }}">View correction</a>
</p>

<p>{{ $organizationName }}</p>
