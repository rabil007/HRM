<?php

namespace App\Support\EmployeeDocuments;

use App\Models\DocumentRequirement;
use App\Models\DocumentType;
use App\Models\User;

final class DocumentTypeDetailPresenter
{
    /**
     * @return array{
     *     id: int,
     *     title: string,
     *     is_active: bool,
     *     status_label: string,
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
     *         label: string,
     *         requirement_label: 'Required'|'Optional',
     *         scope_kind: 'optional'|'all_employees'|'selected_groups',
     *         scope_summary: string,
     *         applies_to_label: string,
     *         who_needs_copy: string,
     *         matching_rule_applies: bool,
     *         targets: array{
     *             departments: list<array{id: int, name: string}>,
     *             positions: list<array{id: int, title: string}>,
     *             ranks: list<array{id: int, name: string}>,
     *             projects: list<array{id: int, title: string}>
     *         },
     *         tracked_details: list<array{key: string, label: string}>
     *     },
     *     compliance_links: list<array{label: string, href: string}>
     * }
     */
    public static function toArray(
        DocumentType $documentType,
        int $companyId,
        ?User $user = null,
    ): array {
        /** @var DocumentRequirement|null $requirement */
        $requirement = $documentType->relationLoaded('requirements')
            ? $documentType->requirements->first()
            : $documentType->requirements()
                ->where('company_id', $companyId)
                ->with([
                    'departments:id,name',
                    'positions:id,title',
                    'ranks:id,name',
                    'projects:id,title',
                ])
                ->first();

        $base = DocumentRequirementPresenter::toArray(
            $requirement instanceof DocumentRequirement ? $requirement : null,
        );

        $isRequired = $base['is_required'];
        $requiredForAll = $base['required_for_all'];

        $scopeKind = 'optional';
        $scopeSummary = 'Optional document';
        $appliesToLabel = '—';
        $whoNeedsCopy = 'This document is optional and is not enforced for employee compliance.';
        $matchingRuleApplies = false;

        if ($isRequired && $requiredForAll) {
            $scopeKind = 'all_employees';
            $scopeSummary = 'Required for all employees';
            $appliesToLabel = 'All employees';
            $whoNeedsCopy = 'Required for all active employees.';
        } elseif ($isRequired) {
            $scopeKind = 'selected_groups';
            $scopeSummary = 'Required for selected groups';
            $appliesToLabel = $base['label'] !== 'Optional'
                ? $base['label']
                : 'Selected groups';
            $whoNeedsCopy = 'Required for selected employees.';
            $matchingRuleApplies = true;
        }

        $targets = [
            'departments' => [],
            'positions' => [],
            'ranks' => [],
            'projects' => [],
        ];

        if ($requirement instanceof DocumentRequirement && $isRequired && ! $requiredForAll) {
            $targets = [
                'departments' => $requirement->departments
                    ->map(fn ($department): array => [
                        'id' => (int) $department->id,
                        'name' => (string) $department->name,
                    ])
                    ->values()
                    ->all(),
                'positions' => $requirement->positions
                    ->map(fn ($position): array => [
                        'id' => (int) $position->id,
                        'title' => (string) $position->title,
                    ])
                    ->values()
                    ->all(),
                'ranks' => $requirement->ranks
                    ->map(fn ($rank): array => [
                        'id' => (int) $rank->id,
                        'name' => (string) $rank->name,
                    ])
                    ->values()
                    ->all(),
                'projects' => $requirement->projects
                    ->map(fn ($project): array => [
                        'id' => (int) $project->id,
                        'title' => (string) $project->title,
                    ])
                    ->values()
                    ->all(),
            ];
        }

        $trackedDetails = [];

        if ($base['require_document_number']) {
            $trackedDetails[] = [
                'key' => 'document_number',
                'label' => 'Document number',
            ];
        }

        if ($base['require_issue_date']) {
            $trackedDetails[] = [
                'key' => 'issue_date',
                'label' => 'Issue date',
            ];
        }

        if ($base['require_expiry_date']) {
            $trackedDetails[] = [
                'key' => 'expiry_date',
                'label' => 'Expiry date',
            ];
        }

        return [
            'id' => $documentType->id,
            'title' => $documentType->title,
            'is_active' => (bool) $documentType->is_active,
            'status_label' => $documentType->is_active ? 'Active' : 'Inactive',
            'requirement' => [
                ...$base,
                'requirement_label' => $isRequired ? 'Required' : 'Optional',
                'scope_kind' => $scopeKind,
                'scope_summary' => $scopeSummary,
                'applies_to_label' => $appliesToLabel,
                'who_needs_copy' => $whoNeedsCopy,
                'matching_rule_applies' => $matchingRuleApplies,
                'targets' => $targets,
                'tracked_details' => $trackedDetails,
            ],
            'compliance_links' => self::complianceLinks(
                $documentType->id,
                $isRequired,
                $user,
            ),
        ];
    }

    /**
     * @return list<array{label: string, href: string}>
     */
    private static function complianceLinks(
        int $documentTypeId,
        bool $isRequired,
        ?User $user,
    ): array {
        if (! $isRequired || ! ($user?->can('documents.view') ?? false)) {
            return [];
        }

        return [
            [
                'label' => 'View missing employees',
                'href' => route('organization.documents.library', [
                    'requirement_status' => 'missing',
                    'document_type_id' => $documentTypeId,
                ]),
            ],
            [
                'label' => 'View documents',
                'href' => route('organization.documents.library', [
                    'document_type_id' => $documentTypeId,
                ]),
            ],
        ];
    }
}
