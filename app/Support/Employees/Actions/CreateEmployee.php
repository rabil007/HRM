<?php

namespace App\Support\Employees\Actions;

use App\Models\DocumentType;
use App\Models\Employee;
use App\Models\EmployeeBankAccount;
use App\Models\EmployeeContract;
use App\Support\EmployeeDocuments\StoresEmployeeDocument;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;

final class CreateEmployee
{
    /**
     * @param  array<string, mixed>  $validated
     */
    public function handle(array $validated, int $companyId, ?int $userId = null, ?UploadedFile $image = null): Employee
    {
        $data = $validated;
        $data['company_id'] = $companyId;

        $documents = $data['documents'] ?? [];
        unset($data['documents']);

        $primaryBankId = $data['bank_id'] ?? null;
        $primaryIban = $data['iban'] ?? null;
        $primaryAccountName = $data['account_name'] ?? null;
        unset($data['bank_id'], $data['iban'], $data['account_name']);

        if ($primaryAccountName === '') {
            $primaryAccountName = null;
        }

        if ($image !== null) {
            $data['image'] = $image->storePublicly(
                "employees/{$companyId}/images",
                ['disk' => 'public']
            );
        }

        $contract = [
            'contract_type' => $data['contract_type'],
            'start_date' => $data['start_date'],
            'end_date' => $data['end_date'] ?? null,
            'labor_contract_id' => $data['labor_contract_id'] ?? null,
            'basic_salary' => $data['basic_salary'] ?? null,
            'housing_allowance' => $data['housing_allowance'] ?? null,
            'transport_allowance' => $data['transport_allowance'] ?? null,
            'other_allowances' => $data['other_allowances'] ?? null,
            'note' => isset($data['note']) && trim((string) $data['note']) !== ''
                ? trim((string) $data['note'])
                : null,
            'status' => 'active',
        ];

        unset(
            $data['contract_type'],
            $data['start_date'],
            $data['end_date'],
            $data['labor_contract_id'],
            $data['basic_salary'],
            $data['housing_allowance'],
            $data['transport_allowance'],
            $data['other_allowances'],
            $data['note'],
        );

        if (($data['religion_id'] ?? null) === '') {
            $data['religion_id'] = null;
        }

        if (($data['gender_id'] ?? null) === '') {
            $data['gender_id'] = null;
        }

        if (($data['visa_type_id'] ?? null) === '') {
            $data['visa_type_id'] = null;
        }

        if (($data['bank_id'] ?? null) === '') {
            $data['bank_id'] = null;
        }

        foreach ([
            'user_id',
            'branch_id',
            'department_id',
            'position_id',
            'rank_id',
            'manager_id',
            'date_of_birth',
            'nationality_id',
            'visa_type_id',
            'marital_status',
            'personal_email',
            'work_email',
            'phone',
            'emergency_contact',
            'emergency_phone',
            'address',
            'iban',
            'bank_id',
            'emirates_id',
            'passport_number',
            'labor_card_number',
            'termination_date',
            'termination_reason',
        ] as $key) {
            if (($data[$key] ?? null) === '') {
                $data[$key] = null;
            }
        }

        $data['status'] = $data['status'] ?? 'active';

        $employee = Employee::create($data);

        EmployeeContract::query()->create([
            'company_id' => $companyId,
            'employee_id' => $employee->id,
            ...$contract,
        ]);

        if ($primaryBankId || $primaryIban || $primaryAccountName) {
            EmployeeBankAccount::query()
                ->where('company_id', $companyId)
                ->where('employee_id', $employee->id)
                ->where('is_primary', true)
                ->update(['is_primary' => false]);

            EmployeeBankAccount::query()->create([
                'company_id' => $companyId,
                'employee_id' => $employee->id,
                'bank_id' => $primaryBankId ?: null,
                'iban' => $primaryIban ?: null,
                'account_name' => $primaryAccountName ?: null,
                'is_primary' => true,
            ]);
        }

        if (is_array($documents) && count($documents) > 0) {
            $this->storeOnboardingDocuments($employee, $documents, $companyId, $userId);
        }

        return $employee;
    }

    /**
     * @param  list<array<string, mixed>>  $documents
     */
    private function storeOnboardingDocuments(Employee $employee, array $documents, int $companyId, ?int $userId): void
    {
        $documentStore = app(StoresEmployeeDocument::class);
        $docTypesById = DocumentType::query()
            ->where('is_active', true)
            ->get(['id', 'title'])
            ->keyBy(fn (DocumentType $documentType) => (string) $documentType->id);

        foreach ($documents as $document) {
            if (! is_array($document)) {
                continue;
            }

            $documentTypeKey = (string) ($document['type'] ?? '');
            $files = $document['files'] ?? [];

            if ($documentTypeKey === '' || ! is_array($files) || count($files) === 0) {
                continue;
            }

            $documentType = $docTypesById->get($documentTypeKey);

            if (! $documentType && ctype_digit($documentTypeKey)) {
                $found = DocumentType::query()->find((int) $documentTypeKey);
                if ($found instanceof DocumentType) {
                    $documentType = $found;
                    $docTypesById->put($documentTypeKey, $documentType);
                }
            }

            if (! $documentType) {
                $derivedTitle = Str::headline(str_replace(['_', '-'], ' ', $documentTypeKey));
                $documentType = DocumentType::query()->firstOrCreate(
                    ['title' => $derivedTitle],
                    ['is_active' => true],
                );
                $docTypesById->put((string) $documentType->id, $documentType);
            }

            foreach ($files as $file) {
                if (! $file) {
                    continue;
                }

                $documentStore->create($employee, $documentType, $file, [
                    'title' => $documentType->title,
                    'issue_date' => $document['issue_date'] ?? null,
                    'expiry_date' => $document['expiry_date'] ?? null,
                    'document_number' => $document['document_number'] ?? null,
                    'notes' => null,
                ], $companyId, $userId);
            }
        }
    }
}
