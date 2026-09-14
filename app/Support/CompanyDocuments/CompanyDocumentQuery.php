<?php

namespace App\Support\CompanyDocuments;

use App\Models\Branch;
use App\Models\Company;
use App\Models\CompanyDocument;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

class CompanyDocumentQuery
{
    public function paginateForCompany(Company $company, string $search, ?int $documentTypeId, string $expiryStatus, int $perPage): LengthAwarePaginator
    {
        return $this->applyFilters(
            $this->baseCompanyQuery($company),
            $search,
            $documentTypeId,
            $expiryStatus,
        )
            ->latest('id')
            ->paginate($perPage)
            ->withQueryString();
    }

    public function paginateForBranch(Company $company, Branch $branch, string $search, ?int $documentTypeId, string $expiryStatus, int $perPage): LengthAwarePaginator
    {
        return $this->applyFilters(
            $this->baseBranchQuery($company, $branch),
            $search,
            $documentTypeId,
            $expiryStatus,
        )
            ->latest('id')
            ->paginate($perPage)
            ->withQueryString();
    }

    /**
     * Backward-compatible alias for paginateForCompany.
     */
    public function paginate(Company $company, string $search, ?int $documentTypeId, string $expiryStatus, int $perPage): LengthAwarePaginator
    {
        return $this->paginateForCompany($company, $search, $documentTypeId, $expiryStatus, $perPage);
    }

    /** @return array{total: int, valid: int, expiring_soon: int, expired: int} */
    public function summaryForCompany(Company $company): array
    {
        $query = CompanyDocument::query()->forCompanyOnly($company->id);

        return [
            'total' => (clone $query)->count(),
            'valid' => $this->applyExpiryStatus(clone $query, 'valid')->count(),
            'expiring_soon' => $this->applyExpiryStatus(clone $query, 'expiring_soon')->count(),
            'expired' => $this->applyExpiryStatus(clone $query, 'expired')->count(),
        ];
    }

    /**
     * Backward-compatible alias for summaryForCompany.
     *
     * @return array{total: int, valid: int, expiring_soon: int, expired: int}
     */
    public function summary(Company $company): array
    {
        return $this->summaryForCompany($company);
    }

    /** @return array{total: int, valid: int, expiring_soon: int, expired: int} */
    public function summaryForBranch(Company $company, Branch $branch): array
    {
        $query = CompanyDocument::query()->forBranch($company->id, $branch->id);

        return [
            'total' => (clone $query)->count(),
            'valid' => $this->applyExpiryStatus(clone $query, 'valid')->count(),
            'expiring_soon' => $this->applyExpiryStatus(clone $query, 'expiring_soon')->count(),
            'expired' => $this->applyExpiryStatus(clone $query, 'expired')->count(),
        ];
    }

    /** @return list<array<string, mixed>> */
    public function recent(Company $company, int $limit = 5): array
    {
        return $this->baseCompanyQuery($company)
            ->latest('id')
            ->limit($limit)
            ->get()
            ->map(fn (CompanyDocument $document) => $this->present($document))
            ->all();
    }

    /** @return list<array<string, mixed>> */
    public function recentForBranch(Company $company, Branch $branch, int $limit = 5): array
    {
        return $this->baseBranchQuery($company, $branch)
            ->latest('id')
            ->limit($limit)
            ->get()
            ->map(fn (CompanyDocument $document) => $this->present($document, $branch))
            ->all();
    }

    /** @return array<string, mixed> */
    public function present(CompanyDocument $document, ?Branch $branch = null): array
    {
        $branchId = $branch?->id ?? $document->branch_id;
        $previewUrl = $branchId
            ? route('organization.branches.documents.preview', [$branchId, $document->id])
            : route('organization.companies.documents.preview', [$document->company_id, $document->id]);
        $downloadUrl = $branchId
            ? route('organization.branches.documents.download', [$branchId, $document->id])
            : route('organization.companies.documents.download', [$document->company_id, $document->id]);

        return [
            'id' => $document->id,
            'title' => $document->title ?? $document->documentType?->title ?? $document->original_filename,
            'document_type' => $document->documentType ? [
                'id' => $document->documentType->id,
                'title' => $document->documentType->title,
            ] : null,
            'branch_id' => $document->branch_id,
            'branch' => $document->branch ? [
                'id' => $document->branch->id,
                'name' => $document->branch->name,
            ] : null,
            'document_number' => $document->document_number,
            'issue_date' => $document->issue_date?->toDateString(),
            'expiry_date' => $document->expiry_date?->toDateString(),
            'expiry_status' => $document->expiry_status,
            'expiry_label' => $document->expiry_label,
            'remaining_days' => $document->remaining_days,
            'notes' => $document->notes,
            'original_filename' => $document->original_filename,
            'mime_type' => $document->mime_type,
            'size_bytes' => $document->size_bytes,
            'current_version' => $document->current_version,
            'can_preview' => $document->can_preview,
            'uploaded_by' => $document->uploader?->name,
            'uploaded_at' => $document->created_at?->toIso8601String(),
            'replaced_at' => $document->replaced_at?->toIso8601String(),
            'preview_url' => $previewUrl,
            'download_url' => $downloadUrl,
        ];
    }

    public function baseCompanyQuery(Company $company): Builder
    {
        return CompanyDocument::query()
            ->forCompanyOnly($company->id)
            ->with(['documentType:id,title', 'uploader:id,name']);
    }

    public function baseBranchQuery(Company $company, Branch $branch): Builder
    {
        return CompanyDocument::query()
            ->forBranch($company->id, $branch->id)
            ->with(['documentType:id,title', 'uploader:id,name', 'branch:id,name']);
    }

    private function applyFilters(Builder $query, string $search, ?int $documentTypeId, string $expiryStatus): Builder
    {
        return $query
            ->when($search !== '', function (Builder $query) use ($search): void {
                $query->where(function (Builder $inner) use ($search): void {
                    $inner->where('title', 'like', "%{$search}%")
                        ->orWhere('document_number', 'like', "%{$search}%")
                        ->orWhere('original_filename', 'like', "%{$search}%")
                        ->orWhereHas('documentType', fn (Builder $type) => $type->where('title', 'like', "%{$search}%"));
                });
            })
            ->when($documentTypeId, fn (Builder $query) => $query->where('document_type_id', $documentTypeId))
            ->when($expiryStatus !== 'all', fn (Builder $query) => $this->applyExpiryStatus($query, $expiryStatus));
    }

    private function applyExpiryStatus(Builder $query, string $status): Builder
    {
        $today = now()->toDateString();
        $soon = now()->addDays(30)->toDateString();

        return match ($status) {
            'expired' => $query->whereDate('expiry_date', '<', $today),
            'expiring_soon' => $query
                ->whereNotNull('expiry_date')
                ->whereDate('expiry_date', '>=', $today)
                ->whereDate('expiry_date', '<=', $soon),
            'valid' => $query->where(function (Builder $valid) use ($soon): void {
                $valid->whereNull('expiry_date')->orWhereDate('expiry_date', '>', $soon);
            }),
            default => $query,
        };
    }
}
