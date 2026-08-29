<?php

namespace App\Support\Documents\Signing;

use App\Models\DocumentSigningPreset;

final class DocumentSigningPresetQuery
{
    public function __construct(
        private DocumentSigningPresetPresenter $presenter,
    ) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function activeForCompany(int $companyId): array
    {
        return DocumentSigningPreset::query()
            ->forCompany($companyId)
            ->active()
            ->with(['steps.targetUser:id,name,email'])
            ->orderBy('name')
            ->get()
            ->map(fn (DocumentSigningPreset $preset): array => $this->presenter->detail($preset))
            ->values()
            ->all();
    }
}
