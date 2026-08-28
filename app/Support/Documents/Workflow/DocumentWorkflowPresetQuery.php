<?php

namespace App\Support\Documents\Workflow;

use App\Models\DocumentWorkflowPreset;

final class DocumentWorkflowPresetQuery
{
    public function __construct(
        private readonly DocumentWorkflowPresetPresenter $presenter = new DocumentWorkflowPresetPresenter,
    ) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function activeForCompany(int $companyId): array
    {
        return DocumentWorkflowPreset::query()
            ->forCompany($companyId)
            ->active()
            ->with([
                'stages.targets.targetUser:id,name',
                'stages.targets.targetRole:id,name',
            ])
            ->orderBy('name')
            ->get()
            ->map(fn (DocumentWorkflowPreset $preset): array => $this->presenter->detail($preset))
            ->all();
    }
}
