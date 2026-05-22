<table class="cv-head">
    <colgroup>
        @for ($i = 0; $i < 12; $i++)
            <col style="width:8.333%">
        @endfor
    </colgroup>
    <tr class="cv-head-brand">
        <td colspan="2" class="head-spacer">&nbsp;</td>
        <td colspan="8" class="head-title-cell">
            <div class="head-title">ADNOC Logistics &amp; Services</div>
            <div class="head-subtitle">Standard CV Form (Seafarer)</div>
        </td>
        <td colspan="2" class="head-logo-cell">
            @if (! empty($logo_url))
                <img src="{{ $logo_url }}" alt="ADNOC">
            @else
                &nbsp;
            @endif
        </td>
    </tr>
    <tr class="head-meta">
        <td colspan="2" class="lbl">SOURCE OF CV.</td>
        <td colspan="8">&nbsp;</td>
        <td colspan="2" class="head-source">{{ $source_of_cv }}</td>
    </tr>
</table>
