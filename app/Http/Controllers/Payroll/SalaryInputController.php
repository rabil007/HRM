<?php

namespace App\Http\Controllers\Payroll;

use App\Http\Controllers\Controller;
use App\Http\Requests\Organization\Payroll\StoreSalaryInputRequest;
use App\Http\Requests\Organization\Payroll\UpdateSalaryInputRequest;
use App\Models\PayrollPeriod;
use App\Models\SalaryInput;
use App\Support\Payroll\Actions\DeleteSalaryInput;
use App\Support\Payroll\Actions\RecalculateOfficePayroll;
use App\Support\Payroll\Actions\StoreSalaryInput;
use App\Support\Payroll\Actions\UpdateSalaryInput;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class SalaryInputController extends Controller
{
    public function store(
        StoreSalaryInputRequest $request,
        PayrollPeriod $payrollPeriod,
        StoreSalaryInput $storeSalaryInput,
    ): RedirectResponse {
        $companyId = (int) $request->attributes->get('current_company_id');
        abort_unless((int) $payrollPeriod->company_id === $companyId, 404);

        $storeSalaryInput->handle(
            $payrollPeriod,
            $request->employee(),
            $request->salaryInputData(),
        );

        return $this->redirectAfterMutation($request, $payrollPeriod, 'Salary input added.');
    }

    public function update(
        UpdateSalaryInputRequest $request,
        PayrollPeriod $payrollPeriod,
        SalaryInput $salaryInput,
        UpdateSalaryInput $updateSalaryInput,
    ): RedirectResponse {
        $companyId = (int) $request->attributes->get('current_company_id');
        abort_unless((int) $payrollPeriod->company_id === $companyId, 404);

        $updateSalaryInput->handle(
            $payrollPeriod,
            $salaryInput,
            $request->salaryInputData(),
        );

        return $this->redirectAfterMutation($request, $payrollPeriod, 'Salary input updated.');
    }

    public function destroy(
        Request $request,
        PayrollPeriod $payrollPeriod,
        SalaryInput $salaryInput,
        DeleteSalaryInput $deleteSalaryInput,
    ): RedirectResponse {
        abort_unless(
            $request->user()?->can('payroll.salary_inputs.delete')
            || $request->user()?->can('payroll.periods.update'),
            403,
        );

        $companyId = (int) $request->attributes->get('current_company_id');
        abort_unless((int) $payrollPeriod->company_id === $companyId, 404);

        $deleteSalaryInput->handle($payrollPeriod, $salaryInput);

        return $this->redirectAfterMutation($request, $payrollPeriod, 'Salary input removed.');
    }

    public function recalculate(
        Request $request,
        PayrollPeriod $payrollPeriod,
        RecalculateOfficePayroll $recalculateOfficePayroll,
    ): RedirectResponse {
        abort_unless(
            $request->user()?->can('payroll.periods.recalculate')
            || $request->user()?->can('payroll.periods.update'),
            403,
        );

        $companyId = (int) $request->attributes->get('current_company_id');
        abort_unless((int) $payrollPeriod->company_id === $companyId, 404);

        $updatedCount = $recalculateOfficePayroll->handle($payrollPeriod);

        return redirect()
            ->route('payroll.show', ['payrollPeriod' => $payrollPeriod, 'tab' => 'payroll'])
            ->with('success', "Recalculated payroll for {$updatedCount} employee(s).");
    }

    private function redirectAfterMutation(
        Request $request,
        PayrollPeriod $payrollPeriod,
        string $message,
    ): RedirectResponse {
        if (str_contains((string) $request->headers->get('referer', ''), '/payroll')) {
            return back()->with('success', $message);
        }

        return redirect()
            ->route('payroll.show', ['payrollPeriod' => $payrollPeriod, 'tab' => 'payroll'])
            ->with('success', $message);
    }
}
