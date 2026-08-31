<?php

namespace App\Http\Controllers\Settings\MasterData;

use App\Http\Controllers\Concerns\ReturnsQuickCreateJson;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Settings\MasterData\Concerns\PaginatesMasterDataIndex;
use App\Http\Requests\Settings\MasterData\ImportDocumentTypesRequest;
use App\Http\Requests\Settings\MasterData\StoreDocumentTypeRequest;
use App\Http\Requests\Settings\MasterData\UpdateDocumentTypeRequest;
use App\Models\DocumentRequirement;
use App\Models\DocumentType;
use App\Support\EmployeeDocuments\Actions\SyncDocumentRequirement;
use App\Support\EmployeeDocuments\DocumentRequirementFormOptions;
use App\Support\EmployeeDocuments\DocumentRequirementPresenter;
use App\Support\EmployeeDocuments\DocumentTypeDetailPresenter;
use App\Support\EmployeeDocuments\DocumentTypeRecentActivityQuery;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

class DocumentTypeController extends Controller
{
    use PaginatesMasterDataIndex;
    use ReturnsQuickCreateJson;

    public function index(Request $request): InertiaResponse
    {
        $companyId = (int) $request->attributes->get('current_company_id');

        $page = $this->paginateMasterDataIndex(
            $request,
            DocumentType::query()
                ->orderBy('title')
                ->with($this->requirementRelationsForCompany($companyId))
                ->select(['id', 'title', 'is_active']),
            ['title'],
            fn (DocumentType $documentType) => $this->toIndexArray($documentType),
        );

        return Inertia::render('organization/documents/configuration/document-types', [
            'document_types' => $page['items'],
            'pagination' => $page['pagination'],
            'search' => $page['search'],
            'open_document_type' => $this->resolveOpenDocumentType($request, $companyId),
            ...DocumentRequirementFormOptions::for($companyId),
        ]);
    }

    public function show(Request $request, DocumentType $documentType): InertiaResponse
    {
        $companyId = (int) $request->attributes->get('current_company_id');
        $user = $request->user();

        $documentType->load($this->requirementRelationsForCompany($companyId));

        return Inertia::render('organization/documents/configuration/document-type-show', [
            'document_type' => DocumentTypeDetailPresenter::toArray($documentType, $companyId, $user),
            'can' => [
                'update' => $user?->can('settings.master-data.document-types.update') ?? false,
                'delete' => $user?->can('settings.master-data.document-types.delete') ?? false,
            ],
            'recent_activity' => DocumentTypeRecentActivityQuery::for(
                $user,
                $companyId,
                $documentType,
            ),
            'can_view_audit' => $user?->can('audit.view') ?? false,
            ...DocumentRequirementFormOptions::for($companyId),
        ]);
    }

    public function store(StoreDocumentTypeRequest $request, SyncDocumentRequirement $sync): JsonResponse|RedirectResponse
    {
        $companyId = (int) $request->attributes->get('current_company_id');
        $validated = $request->validated();
        $typeData = Arr::only($validated, ['title', 'is_active']);
        $typeData['is_active'] = $typeData['is_active'] ?? true;

        return DB::transaction(function () use ($request, $sync, $companyId, $validated, $typeData): JsonResponse|RedirectResponse {
            $existing = $this->findExistingQuickCreate(DocumentType::class, 'title', (string) $typeData['title']);

            if ($existing !== null) {
                return $this->storeRedirectOrQuickCreateJson(
                    $request,
                    $existing,
                    $this->indexRedirect()->with('success', 'Document type created successfully.'),
                    'title',
                );
            }

            $documentType = DocumentType::query()->create($typeData);

            if ($request->exists('is_required')) {
                $sync->handle($companyId, $documentType, $validated, $request->user());
            }

            return $this->storeRedirectOrQuickCreateJson(
                $request,
                $documentType,
                $this->indexRedirect()->with('success', 'Document type created successfully.'),
                'title',
            );
        });
    }

    public function update(
        UpdateDocumentTypeRequest $request,
        DocumentType $document_type,
        SyncDocumentRequirement $sync,
    ): RedirectResponse {
        $companyId = (int) $request->attributes->get('current_company_id');
        $validated = $request->validated();

        DB::transaction(function () use ($request, $document_type, $sync, $companyId, $validated): void {
            $document_type->update(Arr::only($validated, ['title', 'is_active']));

            if ($request->exists('is_required')) {
                $sync->handle($companyId, $document_type, $validated, $request->user());
            }
        });

        if (($validated['redirect_to'] ?? null) === 'show') {
            return redirect()
                ->route('organization.documents.configuration.show', $document_type)
                ->with('success', 'Document type updated successfully.');
        }

        return $this->indexRedirect()->with('success', 'Document type updated successfully.');
    }

    public function destroy(DocumentType $document_type): RedirectResponse
    {
        $document_type->delete();

        return $this->indexRedirect()->with('success', 'Document type deleted successfully.');
    }

    public function importTemplate(): Response
    {
        $csv = "title,is_active\nPassport Copy,yes\nVisa,yes\n";

        return response($csv, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="document-types-import-template.csv"',
        ]);
    }

    public function import(ImportDocumentTypesRequest $request): RedirectResponse
    {
        $uploaded = $request->file('file');
        $path = $uploaded->getRealPath() ?: $uploaded->path();
        $handle = fopen((string) $path, 'r');

        if ($handle === false) {
            return $this->indexRedirect()
                ->withErrors(['file' => 'Could not read the uploaded file.']);
        }

        $header = fgetcsv($handle);
        if (! is_array($header) || count($header) === 0) {
            fclose($handle);

            return $this->indexRedirect()
                ->withErrors(['file' => 'The CSV file is empty.']);
        }

        $map = [];
        foreach ($header as $index => $cell) {
            $key = mb_strtolower(trim((string) $cell));
            if (in_array($key, ['title', 'name', 'document type', 'type'], true)) {
                $map['title'] = (int) $index;
            }
            if (in_array($key, ['active', 'is_active', 'status', 'enabled'], true)) {
                $map['active'] = (int) $index;
            }
        }

        if (! isset($map['title'])) {
            fclose($handle);

            return $this->indexRedirect()
                ->withErrors(['file' => 'The CSV must include a title column.']);
        }

        $imported = 0;
        $emptyTitles = 0;

        while (($row = fgetcsv($handle)) !== false) {
            if (! is_array($row)) {
                continue;
            }

            $title = trim((string) ($row[$map['title']] ?? ''));
            if ($title === '') {
                $emptyTitles++;

                continue;
            }

            $active = true;
            if (isset($map['active'])) {
                $v = mb_strtolower(trim((string) ($row[$map['active']] ?? '')));
                $active = $v === '' || in_array($v, ['1', 'yes', 'true', 'y', 'active'], true);
            }

            DocumentType::query()->updateOrCreate(
                ['title' => $title],
                ['is_active' => $active],
            );
            $imported++;

            if ($imported > 2000) {
                break;
            }
        }

        fclose($handle);

        if ($imported === 0) {
            return $this->indexRedirect()
                ->withErrors([
                    'file' => $emptyTitles > 0
                        ? "No rows were imported. {$emptyTitles} row(s) had an empty title."
                        : 'No rows were imported. Ensure each row has a title.',
                ]);
        }

        return $this->indexRedirect()
            ->with('success', "Imported {$imported} document type row(s).");
    }

    private function indexRedirect(): RedirectResponse
    {
        return redirect()->route('organization.documents.configuration');
    }

    /**
     * @return array<string, callable(HasMany): mixed>
     */
    private function requirementRelationsForCompany(int $companyId): array
    {
        return [
            'requirements' => fn (HasMany $query) => $query
                ->where('company_id', $companyId)
                ->with([
                    'departments:id,name',
                    'positions:id,title',
                    'ranks:id,name',
                    'projects:id,title',
                ]),
        ];
    }

    /**
     * @return array{
     *     id: int,
     *     title: string,
     *     is_active: bool,
     *     requirement: array{
     *         is_required: bool,
     *         required_for_all: bool,
     *         department_ids: list<int>,
     *         position_ids: list<int>,
     *         rank_ids: list<int>,
     *         project_ids: list<int>,
     *         require_issue_date: bool,
     *         require_expiry_date: bool,
     *         require_document_number: bool,
     *         label: string
     *     }
     * }|null
     */
    private function resolveOpenDocumentType(Request $request, int $companyId): ?array
    {
        $editId = (int) $request->query('edit', 0);

        if ($editId <= 0) {
            return null;
        }

        $documentType = DocumentType::query()
            ->with($this->requirementRelationsForCompany($companyId))
            ->select(['id', 'title', 'is_active'])
            ->find($editId);

        if (! $documentType instanceof DocumentType) {
            return null;
        }

        return $this->toIndexArray($documentType);
    }

    /**
     * @return array{
     *     id: int,
     *     title: string,
     *     is_active: bool,
     *     requirement: array{
     *         is_required: bool,
     *         required_for_all: bool,
     *         department_ids: list<int>,
     *         position_ids: list<int>,
     *         rank_ids: list<int>,
     *         project_ids: list<int>,
     *         require_issue_date: bool,
     *         require_expiry_date: bool,
     *         require_document_number: bool,
     *         label: string
     *     }
     * }
     */
    private function toIndexArray(DocumentType $documentType): array
    {
        $requirement = $documentType->requirements->first();

        return [
            'id' => $documentType->id,
            'title' => $documentType->title,
            'is_active' => (bool) $documentType->is_active,
            'requirement' => DocumentRequirementPresenter::toArray(
                $requirement instanceof DocumentRequirement ? $requirement : null,
            ),
        ];
    }
}
