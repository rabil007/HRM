<?php

namespace App\Support\BulkDocuments;

use App\Models\BulkDocumentGenerationRun;
use App\Models\DocumentGenerationRun;
use App\Models\DocumentGenerationTemplate;
use App\Models\DocumentGenerationTemplateVersion;
use App\Models\User;
use App\Support\Employees\EmployeeVisibilityScope;

final class DocumentGenerationProgressQuery
{
    public function __construct(
        private DocumentGenerationRunPresenter $presenter = new DocumentGenerationRunPresenter,
    ) {}

    /**
     * @return array<string, mixed>|null
     */
    public function forCurrentUserCustomTemplate(
        int $companyId,
        int $userId,
        DocumentGenerationTemplate $template,
        ?DocumentGenerationTemplateVersion $publishedVersion = null,
        ?User $user = null,
    ): ?array {
        if ($userId < 1) {
            return null;
        }

        $activeQuery = DocumentGenerationRun::query()
            ->forCompany($companyId)
            ->where('document_generation_template_id', $template->id)
            ->where('triggered_by', $userId)
            ->whereIn('status', ['queued', 'running']);

        $this->applyVisibleItemsConstraint($activeQuery, $user, $companyId);

        $activeRun = $activeQuery
            ->latest('id')
            ->first();

        if ($activeRun !== null) {
            return $this->presenter->fromCompanyTemplateRunForUser($activeRun, $user, $companyId);
        }

        $latestQuery = DocumentGenerationRun::query()
            ->forCompany($companyId)
            ->where('document_generation_template_id', $template->id)
            ->where('triggered_by', $userId)
            ->when(
                $publishedVersion !== null,
                fn ($query) => $query->where('document_generation_template_version_id', $publishedVersion->id),
            );

        $this->applyVisibleItemsConstraint($latestQuery, $user, $companyId);

        $latestRun = $latestQuery
            ->latest('id')
            ->first();

        if ($latestRun === null) {
            return null;
        }

        return $this->presenter->fromCompanyTemplateRunForUser($latestRun, $user, $companyId);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function forBuiltIn(int $companyId, string $documentTypeKey, ?User $user = null): ?array
    {
        if ($user !== null && ! EmployeeVisibilityScope::hasUnrestrictedAccess($user, $companyId)) {
            return null;
        }

        $run = BulkDocumentGenerationRun::query()
            ->where('company_id', $companyId)
            ->where('document_type_key', $documentTypeKey)
            ->latest('id')
            ->first();

        if ($run === null) {
            return null;
        }

        return $this->presenter->fromBuiltInRun($run);
    }

    private function applyVisibleItemsConstraint($query, ?User $user, int $companyId): void
    {
        if ($user === null || EmployeeVisibilityScope::hasUnrestrictedAccess($user, $companyId)) {
            return;
        }

        $query->whereHas('items.employee', function ($employeeQuery) use ($user, $companyId): void {
            EmployeeVisibilityScope::apply($employeeQuery, $user, $companyId);
        });
    }
}
