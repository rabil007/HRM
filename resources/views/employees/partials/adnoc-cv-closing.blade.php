<tr><td colspan="12" class="section">SECTION 11 - REFERENCES</td></tr>
<tr>
    <td colspan="3" class="col-h">NAME OF PERSON</td>
    <td colspan="3" class="col-h">COMPANY NAME</td>
    <td colspan="2" class="col-h">COUNTRY</td>
    <td colspan="2" class="col-h">TEL /FAX NO.</td>
    <td colspan="2" class="col-h">EMAIL ID.</td>
</tr>
@forelse ($references as $ref)
    <tr>
        <td colspan="3" class="val">{{ $ref['name'] }}</td>
        <td colspan="3" class="val">{{ $ref['company'] }}</td>
        <td colspan="2" class="val">{{ $ref['country'] }}</td>
        <td colspan="2" class="val">{{ $ref['phone'] }}</td>
        <td colspan="2" class="val">{{ $ref['email'] }}</td>
    </tr>
@empty
    <tr>
        <td colspan="12" class="center" style="padding:4px;">No reference records</td>
    </tr>
@endforelse

<tr><td colspan="12" class="section">SECTION 12 - DECLARATION</td></tr>
<tr>
    <td colspan="12" class="declaration">
        I hereby declare that all statements and particulars written in this document are true and supplied to the best of my knowledge. In addition, I authorize you to contact the referees listed above.
    </td>
</tr>
<tr>
    <td colspan="2" class="lbl">NAME &amp; SIGNATURE</td>
    <td colspan="6" class="val">{{ $full_name }}</td>
    <td colspan="2" class="lbl">DATE</td>
    <td colspan="2" class="val">{{ $declaration_date }}</td>
</tr>
<tr>
    <td colspan="12" class="note">PLEASE ATTACH ALL CERTIFICATES AND DOCUMENTS.</td>
</tr>

<tr><td colspan="12" class="section">SECTION 13 - CV EVALUATION (FOR OFFICE USE ONLY)</td></tr>
<tr>
    <td colspan="12" class="lbl">RECOMMENDATION / REMARKS</td>
</tr>
<tr class="remarks-space"><td colspan="12">&nbsp;</td></tr>
<tr>
    <td colspan="6" class="lbl">Evaluator Name &amp; Designation</td>
    <td colspan="6" class="lbl">HRO REPRESENTATIVE</td>
</tr>
<tr class="blank-compact"><td colspan="6">&nbsp;</td><td colspan="6">&nbsp;</td></tr>

<tr class="cv-bottom-spacer"><td colspan="12">&nbsp;</td></tr>
<tr class="footer-rev footer-rev--final">
    <td colspan="12">FRM-HRA-RMP-032- Rev. 00</td>
</tr>
