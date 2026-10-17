<?php

namespace App\Support\Contracts\Actions;

use App\Models\ContractSalaryRevision;
use App\Models\ContractSalaryRevisionLine;
use App\Models\EmployeeContract;
use App\Support\Contracts\AssertContractSalaryRevisionEditable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class DeleteContractSalaryRevision
{
    public function __construct(
        private readonly MirrorLatestContractSalaryRevision $mirrorLatestRevision,
        private readonly AssertContractSalaryRevisionEditable $assertEditable,
    ) {}

    public function handle(EmployeeContract $contract, ContractSalaryRevision $revision): void
    {
        abort_unless(
            $revision->contract_id === $contract->id
            && $revision->company_id === $contract->company_id,
            404,
        );

        DB::transaction(function () use ($contract, $revision): void {
            $lockedContract = EmployeeContract::query()
                ->whereKey($contract->id)
                ->where('company_id', $contract->company_id)
                ->lockForUpdate()
                ->firstOrFail();

            $lockedRevision = ContractSalaryRevision::query()
                ->whereKey($revision->id)
                ->where('company_id', $lockedContract->company_id)
                ->where('contract_id', $lockedContract->id)
                ->lockForUpdate()
                ->firstOrFail();

            $remainingCount = ContractSalaryRevision::query()
                ->where('contract_id', $lockedContract->id)
                ->whereKeyNot($lockedRevision->id)
                ->count();

            if ($remainingCount === 0) {
                throw ValidationException::withMessages([
                    'salary_revision' => 'The only salary revision on a contract cannot be deleted.',
                ]);
            }

            $this->assertEditable->assertDeleteWouldNotAlterFinalizedPayroll(
                $lockedContract,
                $lockedRevision,
            );

            ContractSalaryRevisionLine::query()
                ->where('revision_id', $lockedRevision->id)
                ->delete();

            $lockedRevision->delete();

            $this->mirrorLatestRevision->handle($lockedContract->fresh());
        });
    }
}
