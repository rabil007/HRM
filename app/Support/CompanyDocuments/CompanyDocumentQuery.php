<?php

namespace App\Support\CompanyDocuments;

use App\Models\Company;
use App\Models\CompanyDocument;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

class CompanyDocumentQuery
{
    public function paginate(Company $company, string $search, ?int $documentTypeId, string $expiryStatus, int $perPage): LengthAwarePaginator
    {
        return $this->baseQuery($company)
            ->when($search !== '', function (Builder $query) use ($search): void {
                $query->where(function (Builder $inner) use ($search): void {
                    $inner->where('title', 'like', "%{$search}%")
                        ->orWhere('document_number', 'like', "%{$search}%")
                        ->orWhere('original_filename', 'like', "%{$search}%")
                        ->orWhereHas('documentType', fn (Builder $type) => $type->where('title', 'like', "%{$search}%"));
                });
            })
            ->when($documentTypeId, fn (Builder $query) => $query->where('document_type_id', $documentTypeId))
            ->when($expiryStatus !== 'all', fn (Builder $query) => $this->applyExpiryStatus($query, $expiryStatus))
            ->latest('id')
            ->paginate($perPage)
            ->withQueryString();
    }

    /** @return array{total: int, valid: int, expiring_soon: int, expired: int} */
    public function summary(Company $company): array
    {
        $query = CompanyDocument::query()->forCompany($company->id);

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
        return $this->baseQuery($company)
            ->latest('id')
            ->limit($limit)
            ->get()
            ->map(fn (CompanyDocument $document) => $this->present($document))
            ->all();
    }

    /** @return array<string, mixed> */
    public function present(CompanyDocument $document): array
    {
        return [
            'id' => $document->id,
            'title' => $document->title ?? $document->documentType?->title ?? $document->original_filename,
            'document_type' => $document->documentType ? [
                'id' => $document->documentType->id,
                'title' => $document->documentType->title,
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
            'preview_url' => route('organization.companies.documents.preview', [$document->company_id, $document->id]),
            'download_url' => route('organization.companies.documents.download', [$document->company_id, $document->id]),
        ];
    }

    private function baseQuery(Company $company): Builder
    {
        return CompanyDocument::query()
            ->forCompany($company->id)
            ->with(['documentType:id,title', 'uploader:id,name']);
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
