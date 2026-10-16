<?php

namespace App\Exports;

use App\Support\EmployeeDocuments\DocumentExpiry;
use App\Support\Settings\PlatformDateFormatter;
use Carbon\Carbon;
use Illuminate\Database\Query\Builder;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStrictNullComparison;

class DocumentRequirementsExport implements FromQuery, ShouldAutoSize, WithHeadings, WithMapping, WithStrictNullComparison
{
    public function __construct(private readonly Builder $query) {}

    public function query(): Builder
    {
        return $this->query;
    }

    /**
     * @return list<string>
     */
    public function headings(): array
    {
        return [
            'Employee No',
            'Employee Name',
            'Department',
            'Document Name',
            'Document Type',
            'Document No.',
            'Issue Date',
            'Expiry Date',
            'Remaining',
            'Status',
            'Uploaded At',
            'Uploaded By',
        ];
    }

    /**
     * @param  object  $row
     * @return list<mixed>
     */
    public function map($row): array
    {
        $status = ucfirst((string) ($row->compliance_status ?? ''));
        $expiryDate = $row->expiry_date ?? null;
        $expiryFormatted = $expiryDate
            ? (PlatformDateFormatter::make()->date(Carbon::parse($expiryDate), '') ?? '')
            : '';
        $issueDate = $row->issue_date ?? null;
        $issueFormatted = $issueDate
            ? (PlatformDateFormatter::make()->date(Carbon::parse($issueDate), '') ?? '')
            : '';
        $uploadedAt = $row->uploaded_at ?? null;
        $uploadedFormatted = $uploadedAt
            ? (PlatformDateFormatter::make()->dateTime(Carbon::parse($uploadedAt), '') ?? '')
            : '';

        $expiryLabel = ($row->compliance_status ?? '') === 'missing'
            ? 'Missing'
            : DocumentExpiry::humanLabel($expiryDate);

        return [
            $row->employee_no ?? '',
            $row->employee_name ?? '',
            $row->department_name ?? '',
            $row->original_filename ?: ($row->document_type_title ?? ''),
            $row->document_type_title ?? '',
            $row->document_number ?? '',
            $issueFormatted,
            $expiryFormatted,
            $expiryLabel,
            $status,
            $uploadedFormatted,
            $row->uploaded_by_name ?? '',
        ];
    }
}
