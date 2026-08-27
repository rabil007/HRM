<?php

namespace App\Support\Documents;

use App\Support\EmployeeDocuments\DocumentBrowseQuery;
use App\Support\EmployeeDocuments\DocumentComplianceQuery;

final class DocumentsOverviewQuery
{
    public function __construct(
        private readonly DocumentBrowseQuery $browse,
        private readonly DocumentComplianceQuery $compliance,
    ) {}

    /**
     * @return array{
     *     summary: array{total_documents: int, expired: int, expiring_30: int, expiring_15: int, expiring_7: int},
     *     requirement_summary: array{required: int, valid: int, expiring: int, expired: int, missing: int},
     *     attention: list<array{key: string, label: string, count: int, query: array<string, string>}>
     * }
     */
    public function forCompany(int $companyId): array
    {
        $summary = $this->browse->expirySummary($companyId);
        $requirementSummary = $this->compliance->summary($companyId);

        return [
            'summary' => $summary,
            'requirement_summary' => $requirementSummary,
            'attention' => $this->attention($summary, $requirementSummary),
        ];
    }

    /**
     * @param  array{total_documents: int, expired: int, expiring_30: int, expiring_15: int, expiring_7: int}  $summary
     * @param  array{required: int, valid: int, expiring: int, expired: int, missing: int}  $requirementSummary
     * @return list<array{key: string, label: string, count: int, query: array<string, string>}>
     */
    private function attention(array $summary, array $requirementSummary): array
    {
        $items = [];

        if ($summary['expired'] > 0) {
            $items[] = [
                'key' => 'expired',
                'label' => 'Expired documents',
                'count' => $summary['expired'],
                'query' => ['expiry' => 'expired'],
            ];
        }

        if ($summary['expiring_7'] > 0) {
            $items[] = [
                'key' => 'expiring_7',
                'label' => 'Expiring within 7 days',
                'count' => $summary['expiring_7'],
                'query' => ['expiry' => 'expiring_7'],
            ];
        } elseif ($summary['expiring_15'] > 0) {
            $items[] = [
                'key' => 'expiring_15',
                'label' => 'Expiring within 15 days',
                'count' => $summary['expiring_15'],
                'query' => ['expiry' => 'expiring_15'],
            ];
        } elseif ($summary['expiring_30'] > 0) {
            $items[] = [
                'key' => 'expiring_30',
                'label' => 'Expiring within 30 days',
                'count' => $summary['expiring_30'],
                'query' => ['expiry' => 'expiring_30'],
            ];
        }

        if ($requirementSummary['missing'] > 0) {
            $items[] = [
                'key' => 'missing',
                'label' => 'Missing required documents',
                'count' => $requirementSummary['missing'],
                'query' => ['requirement_status' => 'missing'],
            ];
        }

        return $items;
    }
}
