<?php

namespace App\Support\Contracts\Actions;

use App\Models\ContractSalaryRevision;
use App\Models\ContractSalaryRevisionLine;
use App\Models\EmployeeContract;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class DeleteContractSalaryRevision
{
    public function __construct(
        private readonly MirrorLatestContractSalaryRevision $mirrorLatestRevision,
    ) {}

    public function handle(EmployeeContract $contract, ContractSalaryRevision $revision): void
    {
        abort_unless(
            $revision->contract_id === $contract->id
            && $revision->company_id === $contract->company_id,
            404,
        );

        $remainingCount = ContractSalaryRevision::query()
            ->where('contract_id', $contract->id)
            ->whereKeyNot($revision->id)
            ->count();

        if ($remainingCount === 0) {
            throw ValidationException::withMessages([
                'salary_revision' => 'The only salary revision on a contract cannot be deleted.',
            ]);
        }

        DB::transaction(function () use ($contract, $revision): void {
            ContractSalaryRevisionLine::query()
                ->where('revision_id', $revision->id)
                ->delete();

            $revision->delete();

            $this->mirrorLatestRevision->handle($contract->fresh());
        });
    }
}
