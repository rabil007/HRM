<?php

namespace App\Http\Controllers\Organization;

use App\Http\Controllers\Controller;
use App\Http\Requests\Organization\BulkDestroyOrganizationRecordsRequest;
use App\Models\EmployeeBankAccount;
use App\Models\EmployeeContract;
use App\Models\EmployeeSeaService;
use App\Models\EmployeeTraining;
use App\Models\PayrollRecord;
use App\Support\EmployeeTrainings\StoresEmployeeTrainingCertificate;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;

class OrganizationBulkRecordController extends Controller
{
    public function __construct(
        private readonly StoresEmployeeTrainingCertificate $certificateStore,
    ) {}

    public function destroySeaServices(BulkDestroyOrganizationRecordsRequest $request): RedirectResponse
    {
        $records = $this->companyRecords($request, EmployeeSeaService::class);

        $records->each->delete();

        return $this->success($records->count(), 'sea service record');
    }

    public function destroyTrainings(BulkDestroyOrganizationRecordsRequest $request): RedirectResponse
    {
        $records = $this->companyRecords($request, EmployeeTraining::class);

        foreach ($records as $training) {
            $this->certificateStore->deleteForTraining($training);
            $training->delete();
        }

        return $this->success($records->count(), 'training record');
    }

    public function destroyBankAccounts(BulkDestroyOrganizationRecordsRequest $request): RedirectResponse
    {
        $records = $this->companyRecords($request, EmployeeBankAccount::class);
        $recordIds = $records->modelKeys();

        if (PayrollRecord::query()->whereIn('employee_bank_account_id', $recordIds)->exists()) {
            return back()->withErrors([
                'bulk_delete' => 'One or more selected bank accounts are linked to pay run records and cannot be removed.',
            ]);
        }

        $companyId = $this->companyId($request);
        $employeeIds = $records->pluck('employee_id')->unique()->map(fn ($id) => (int) $id);

        DB::transaction(function () use ($records, $companyId, $employeeIds): void {
            $records->each->delete();

            $employeeIds->each(
                fn (int $employeeId) => EmployeeBankAccountController::reconcilePrimaryAccounts(
                    $companyId,
                    $employeeId,
                ),
            );
        });

        return $this->success($records->count(), 'bank account');
    }

    public function destroyContracts(BulkDestroyOrganizationRecordsRequest $request): RedirectResponse
    {
        $records = $this->companyRecords($request, EmployeeContract::class);

        if (PayrollRecord::query()->whereIn('contract_id', $records->modelKeys())->exists()) {
            return back()->withErrors([
                'bulk_delete' => 'One or more selected contracts are linked to pay run records and cannot be removed.',
            ]);
        }

        $records->each->delete();

        return $this->success($records->count(), 'contract');
    }

    /**
     * @template TModel of Model
     *
     * @param  class-string<TModel>  $model
     * @return Collection<int, TModel>
     */
    private function companyRecords(
        BulkDestroyOrganizationRecordsRequest $request,
        string $model,
    ): Collection {
        $recordIds = $request->validated('ids');

        $records = $model::query()
            ->where('company_id', $this->companyId($request))
            ->whereIn('id', $recordIds)
            ->get();

        if ($records->count() !== count($recordIds)) {
            abort(404);
        }

        return $records;
    }

    private function companyId(BulkDestroyOrganizationRecordsRequest $request): int
    {
        return (int) $request->attributes->get('current_company_id');
    }

    private function success(int $count, string $singular): RedirectResponse
    {
        $label = $count === 1 ? $singular : $singular.'s';

        return back()->with('success', "Deleted {$count} {$label}.");
    }
}
