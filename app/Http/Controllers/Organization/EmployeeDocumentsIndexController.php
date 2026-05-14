<?php

namespace App\Http\Controllers\Organization;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Department;
use App\Models\DocumentType;
use App\Models\EmployeeDocument;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Inertia\Inertia;

class EmployeeDocumentsIndexController extends Controller
{
    public function __invoke(Request $request)
    {
        $companyId = (int) $request->attributes->get('current_company_id');
        $status = $request->query('status');
        $search = trim((string) $request->query('search', ''));
        $documentType = trim((string) $request->query('document_type', ''));
        $branchId = trim((string) $request->query('branch_id', ''));
        $departmentId = trim((string) $request->query('department_id', ''));
        $expiryFrom = trim((string) $request->query('expiry_from', ''));
        $expiryTo = trim((string) $request->query('expiry_to', ''));
        $uploadedFrom = trim((string) $request->query('uploaded_from', ''));
        $uploadedTo = trim((string) $request->query('uploaded_to', ''));

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
                'document_types.slug as document_type_slug',
                'employees.name',
                'employees.employee_no',
                'employees.branch_id',
                'employees.department_id',
            ])
            ->when($status, fn ($q) => $q->where('employee_documents.status', $status))
            ->when($documentType, function ($q) use ($documentType) {
                $q->where(function ($inner) use ($documentType) {
                    $inner->where('document_types.slug', $documentType)
                        ->orWhere('employee_documents.document_type', $documentType);
                });
            })
            ->when($branchId, fn ($q) => $q->where('employees.branch_id', $branchId))
            ->when($departmentId, fn ($q) => $q->where('employees.department_id', $departmentId))
            ->when($expiryFrom, fn ($q) => $q->whereDate('employee_documents.expiry_date', '>=', $expiryFrom))
            ->when($expiryTo, fn ($q) => $q->whereDate('employee_documents.expiry_date', '<=', $expiryTo))
            ->when($uploadedFrom, fn ($q) => $q->whereDate('employee_documents.created_at', '>=', $uploadedFrom))
            ->when($uploadedTo, fn ($q) => $q->whereDate('employee_documents.created_at', '<=', $uploadedTo))
            ->when($search, function ($q) use ($search) {
                $q->where(function ($inner) use ($search) {
                    $inner->where('employees.name', 'like', "%{$search}%")
                        ->orWhere('employees.employee_no', 'like', "%{$search}%")
                        ->orWhere('employee_documents.document_type', 'like', "%{$search}%")
                        ->orWhere('document_types.title', 'like', "%{$search}%")
                        ->orWhere('employee_documents.title', 'like', "%{$search}%")
                        ->orWhere('employee_documents.document_number', 'like', "%{$search}%");
                });
            })
            ->orderByRaw('CASE WHEN employee_documents.expiry_date IS NULL THEN 1 ELSE 0 END')
            ->orderBy('employee_documents.expiry_date')
            ->orderByDesc('employee_documents.id');

        $paginator = $query->paginate(25)->withQueryString();

        $documents = collect($paginator->items())->map(fn ($doc) => [
            'id' => $doc->id,
            'employee_id' => $doc->employee_id,
            'employee_no' => $doc->employee_no,
            'employee_name' => $doc->name,
            'document_type' => $doc->document_type_slug ?? $doc->document_type,
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
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'from' => $paginator->firstItem(),
                'to' => $paginator->lastItem(),
            ],
            'counts' => $counts,
            'active_status' => $status,
            'search' => $search,
            'filters' => [
                'document_type' => $documentType,
                'branch_id' => $branchId,
                'department_id' => $departmentId,
                'expiry_from' => $expiryFrom,
                'expiry_to' => $expiryTo,
                'uploaded_from' => $uploadedFrom,
                'uploaded_to' => $uploadedTo,
            ],
            'filter_options' => [
                'document_types' => DocumentType::query()
                    ->where('is_active', true)
                    ->orderBy('title')
                    ->get(['id', 'title', 'slug']),
                'branches' => Branch::query()
                    ->where('company_id', $companyId)
                    ->orderBy('name')
                    ->get(['id', 'name']),
                'departments' => Department::query()
                    ->where('company_id', $companyId)
                    ->orderBy('name')
                    ->get(['id', 'name']),
            ],
        ]);
    }
}
