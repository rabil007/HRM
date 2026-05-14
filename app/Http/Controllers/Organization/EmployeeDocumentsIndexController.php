<?php

namespace App\Http\Controllers\Organization;

use App\Http\Controllers\Controller;
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

        $query = EmployeeDocument::query()
            ->where('employee_documents.company_id', $companyId)
            ->join('employees', 'employees.id', '=', 'employee_documents.employee_id')
            ->select([
                'employee_documents.id',
                'employee_documents.employee_id',
                'employee_documents.document_type',
                'employee_documents.title',
                'employee_documents.file_path',
                'employee_documents.issue_date',
                'employee_documents.expiry_date',
                'employee_documents.document_number',
                'employee_documents.status',
                'employee_documents.created_at',
                'employees.first_name',
                'employees.last_name',
                'employees.employee_no',
            ])
            ->when($status, fn ($q) => $q->where('employee_documents.status', $status))
            ->when($search, function ($q) use ($search) {
                $q->where(function ($inner) use ($search) {
                    $inner->where('employees.first_name', 'like', "%{$search}%")
                        ->orWhere('employees.last_name', 'like', "%{$search}%")
                        ->orWhere('employees.employee_no', 'like', "%{$search}%")
                        ->orWhere('employee_documents.document_type', 'like', "%{$search}%")
                        ->orWhere('employee_documents.title', 'like', "%{$search}%")
                        ->orWhere('employee_documents.document_number', 'like', "%{$search}%");
                });
            })
            ->orderByRaw('ISNULL(employee_documents.expiry_date) asc')
            ->orderBy('employee_documents.expiry_date')
            ->orderByDesc('employee_documents.id');

        $paginator = $query->paginate(25)->withQueryString();

        $documents = collect($paginator->items())->map(fn ($doc) => [
            'id' => $doc->id,
            'employee_id' => $doc->employee_id,
            'employee_no' => $doc->employee_no,
            'employee_name' => trim("{$doc->first_name} {$doc->last_name}"),
            'document_type' => $doc->document_type,
            'title' => $doc->title,
            'file_url' => str_starts_with((string) $doc->file_path, 'http')
                ? $doc->file_path
                : asset('storage/'.ltrim((string) $doc->file_path, '/')),
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
        ]);
    }
}
