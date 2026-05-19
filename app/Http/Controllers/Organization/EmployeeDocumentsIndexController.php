<?php

namespace App\Http\Controllers\Organization;

use App\Http\Controllers\Controller;
use App\Models\DocumentType;
use App\Models\EmployeeDocument;
use App\Support\Pagination\ResolvesPerPage;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Inertia\Inertia;

class EmployeeDocumentsIndexController extends Controller
{
    use ResolvesPerPage;

    public function __invoke(Request $request)
    {
        $companyId = (int) $request->attributes->get('current_company_id');
        $perPage = $this->resolvePerPage($request, default: 25);
        $search = trim((string) $request->query('search', ''));
        $documentType = trim((string) $request->query('document_type', ''));
        $expiryWithin = (int) $request->query('expiry_within', 0);

        $query = EmployeeDocument::query()
            ->where('employee_documents.company_id', $companyId)
            ->join('employees', 'employees.id', '=', 'employee_documents.employee_id')
            ->leftJoin('document_types', 'document_types.id', '=', 'employee_documents.document_type_id')
            ->select([
                'employee_documents.id',
                'employee_documents.employee_id',
                'employee_documents.document_type_id',
                'employee_documents.document_type',
                'employee_documents.title',
                'employee_documents.file_path',
                'employee_documents.original_filename',
                'employee_documents.mime_type',
                'employee_documents.size_bytes',
                'employee_documents.current_version',
                'employee_documents.issue_date',
                'employee_documents.expiry_date',
                'employee_documents.document_number',
                'employee_documents.status',
                'employee_documents.created_at',
                'document_types.title as document_type_title',
                'employees.name',
                'employees.employee_no',
            ])
            ->when($documentType, function ($q) use ($documentType) {
                $q->where(function ($inner) use ($documentType) {
                    if (ctype_digit($documentType)) {
                        $inner->where('employee_documents.document_type_id', (int) $documentType);
                    } else {
                        $inner->where('employee_documents.document_type', $documentType);
                    }
                });
            })
            ->when($search, function ($q) use ($search) {
                $q->where(function ($inner) use ($search) {
                    $inner->where('employees.name', 'like', "%{$search}%")
                        ->orWhere('employees.employee_no', 'like', "%{$search}%");
                });
            })
            ->when($expiryWithin > 0, function ($q) use ($expiryWithin) {
                $q->whereNotNull('employee_documents.expiry_date')
                    ->whereBetween('employee_documents.expiry_date', [
                        Carbon::today(),
                        Carbon::today()->addDays($expiryWithin),
                    ]);
            })
            ->orderByRaw('CASE WHEN employee_documents.expiry_date IS NULL THEN 1 ELSE 0 END')
            ->orderBy('employee_documents.expiry_date')
            ->orderByDesc('employee_documents.id');

        $paginator = $query->paginate($perPage)->withQueryString();

        $documents = collect($paginator->items())->map(fn ($doc) => [
            'id' => $doc->id,
            'employee_id' => $doc->employee_id,
            'employee_no' => $doc->employee_no,
            'employee_name' => $doc->name,
            'document_type' => $doc->document_type_id ? (string) $doc->document_type_id : $doc->document_type,
            'document_type_label' => $doc->document_type_title ?? $doc->document_type,
            'title' => $doc->title,
            'file_url' => str_starts_with((string) $doc->file_path, 'http')
                ? $doc->file_path
                : asset('storage/'.ltrim((string) $doc->file_path, '/')),
            'original_filename' => $doc->original_filename,
            'mime_type' => $doc->mime_type,
            'size_bytes' => $doc->size_bytes,
            'current_version' => $doc->current_version,
            'can_preview' => str_starts_with((string) $doc->mime_type, 'image/') || $doc->mime_type === 'application/pdf',
            'issue_date' => $doc->issue_date ? Carbon::parse($doc->issue_date)->toDateString() : null,
            'expiry_date' => $doc->expiry_date ? Carbon::parse($doc->expiry_date)->toDateString() : null,
            'document_number' => $doc->document_number,
            'status' => $doc->status,
            'created_at' => $doc->created_at,
        ])->all();

        $counts = EmployeeDocument::query()
            ->where('company_id', $companyId)
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status')
            ->toArray();

        return Inertia::render('organization/documents', [
            'documents' => $documents,
            'pagination' => $this->paginationMeta($paginator),
            'counts' => $counts,
            'search' => $search,
            'filters' => [
                'document_type' => $documentType,
                'expiry_within' => $expiryWithin ?: '',
            ],
            'filter_options' => [
                'document_types' => DocumentType::query()
                    ->where('is_active', true)
                    ->orderBy('title')
                    ->get(['id', 'title']),
            ],
        ]);
    }
}
