<?php

namespace App\Support\BulkDocuments;

use App\Enums\BulkDocumentSignatureRequestStatus;
use App\Models\BulkDocumentSignatureRequest;
use App\Models\Company;
use InvalidArgumentException;
use SplFileObject;

final class LegacySalaryDeclarationSignatureCutover
{
    /**
     * @return list<array{
     *     company_id: int,
     *     company_name: string,
     *     total: int,
     *     counts: array<string, int>,
     *     awaiting: list<array{
     *         request_id: int,
     *         employee_id: int,
     *         employee_no: ?string,
     *         employee_name: string,
     *         employee_document_id: ?int,
     *         document_type_key: string,
     *         created_at: ?string,
     *         expires_at: ?string
     *     }>
     * }>
     */
    public function report(?int $companyId): array
    {
        $companyIds = $this->companyIdsWithLegacyRequests($companyId);

        return array_map(
            fn (int $id): array => $this->reportForCompany($id),
            $companyIds,
        );
    }

    /**
     * @param  list<array{
     *     employee_id: int,
     *     employee_no: ?string,
     *     employee_name: string,
     *     legacy_request_id: int,
     *     employee_document_id: ?int
     * }>  $employees
     */
    public function writeEmployeeCsv(string $path, array $employees): void
    {
        $directory = dirname($path);

        if ($directory !== '' && $directory !== '.' && ! is_dir($directory) && ! mkdir($directory, 0755, true) && ! is_dir($directory)) {
            throw new InvalidArgumentException("Unable to create export directory [{$directory}].");
        }

        $file = new SplFileObject($path, 'w');
        $file->fputcsv(['employee_id', 'employee_no', 'employee_name', 'legacy_request_id', 'employee_document_id']);

        foreach ($employees as $employee) {
            $file->fputcsv([
                (string) $employee['employee_id'],
                (string) ($employee['employee_no'] ?? ''),
                $employee['employee_name'],
                (string) $employee['legacy_request_id'],
                (string) ($employee['employee_document_id'] ?? ''),
            ]);
        }
    }

    /**
     * @param  list<array{
     *     request_id: int,
     *     employee_id: int,
     *     employee_no: ?string,
     *     employee_name: string,
     *     employee_document_id: ?int,
     *     document_type_key: string,
     *     created_at: ?string,
     *     expires_at: ?string
     * }>  $awaiting
     * @return list<array{
     *     employee_id: int,
     *     employee_no: ?string,
     *     employee_name: string,
     *     legacy_request_id: int,
     *     employee_document_id: ?int
     * }>
     */
    public function awaitingExportRows(array $awaiting): array
    {
        return array_map(fn (array $row): array => [
            'employee_id' => $row['employee_id'],
            'employee_no' => $row['employee_no'],
            'employee_name' => $row['employee_name'],
            'legacy_request_id' => $row['request_id'],
            'employee_document_id' => $row['employee_document_id'],
        ], $awaiting);
    }

    /**
     * @return list<int>
     */
    private function companyIdsWithLegacyRequests(?int $companyId): array
    {
        if ($companyId !== null) {
            if (! Company::query()->whereKey($companyId)->exists()) {
                throw new InvalidArgumentException("Company [{$companyId}] was not found.");
            }

            return [$companyId];
        }

        return BulkDocumentSignatureRequest::query()
            ->where('document_type_key', LegacySalaryDeclarationSigning::DOCUMENT_TYPE_KEY)
            ->distinct()
            ->orderBy('company_id')
            ->pluck('company_id')
            ->map(fn ($id): int => (int) $id)
            ->all();
    }

    /**
     * @return array{
     *     company_id: int,
     *     company_name: string,
     *     total: int,
     *     counts: array<string, int>,
     *     awaiting: list<array{
     *         request_id: int,
     *         employee_id: int,
     *         employee_no: ?string,
     *         employee_name: string,
     *         employee_document_id: ?int,
     *         document_type_key: string,
     *         created_at: ?string,
     *         expires_at: ?string
     *     }>
     * }
     */
    private function reportForCompany(int $companyId): array
    {
        $companyName = (string) Company::query()->whereKey($companyId)->value('name');

        $base = BulkDocumentSignatureRequest::query()
            ->forCompany($companyId)
            ->where('document_type_key', LegacySalaryDeclarationSigning::DOCUMENT_TYPE_KEY);

        $counts = [];

        foreach (BulkDocumentSignatureRequestStatus::cases() as $status) {
            $counts[$status->value] = (clone $base)->where('status', $status)->count();
        }

        $awaiting = (clone $base)
            ->with(['employee:id,name,employee_no'])
            ->where('status', BulkDocumentSignatureRequestStatus::AwaitingSignature)
            ->orderBy('id')
            ->get();

        return [
            'company_id' => $companyId,
            'company_name' => $companyName,
            'total' => (clone $base)->count(),
            'counts' => $counts,
            'awaiting' => $awaiting
                ->map(fn (BulkDocumentSignatureRequest $request): array => [
                    'request_id' => $request->id,
                    'employee_id' => (int) $request->employee_id,
                    'employee_no' => $request->employee?->employee_no,
                    'employee_name' => (string) ($request->employee?->name ?? ''),
                    'employee_document_id' => $request->employee_document_id,
                    'document_type_key' => $request->document_type_key,
                    'created_at' => $request->created_at?->toIso8601String(),
                    'expires_at' => $request->expires_at?->toIso8601String(),
                ])
                ->all(),
        ];
    }
}
