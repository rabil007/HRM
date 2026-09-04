<?php

namespace App\Exports;

use App\Models\EmployeeDocument;
use App\Support\EmployeeDocuments\DocumentExpiry;
use App\Support\EmployeeDocuments\DocumentExpiryStatus;
use Illuminate\Database\Eloquent\Builder;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStrictNullComparison;

class DocumentsExport implements FromQuery, ShouldAutoSize, WithHeadings, WithMapping, WithStrictNullComparison
{
    /**
     * @param  Builder<EmployeeDocument>  $query
     */
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
     * @param  EmployeeDocument  $document
     * @return list<mixed>
     */
    public function map($document): array
    {
        $status = 'Valid';

        if ($document->expiry_date !== null) {
            $resolved = DocumentExpiry::resolve($document->expiry_date);
            $status = match ($resolved) {
                DocumentExpiryStatus::Expired => 'Expired',
                DocumentExpiryStatus::Valid => 'Valid',
                default => 'Expiring soon',
            };
        }

        return [
            $document->employee?->employee_no ?? '',
            $document->employee?->name ?? '',
            $document->employee?->department?->name ?? '',
            $document->original_filename ?: ($document->title ?: $document->document_type_label),
            $document->document_type_label,
            $document->document_number ?? '',
            $document->issue_date?->format('d-m-Y') ?? '',
            $document->expiry_date?->format('d-m-Y') ?? '',
            DocumentExpiry::humanLabel($document->expiry_date),
            $status,
            $document->created_at?->format('d-m-Y H:i') ?? '',
            $document->uploader?->name ?? '',
        ];
    }
}
