<?php

namespace App\Http\Controllers\Payroll;

use App\Http\Controllers\Controller;
use App\Http\Requests\Organization\Payroll\ShowCrewPayrollGenerationPreviewRequest;
use App\Models\PayrollPeriod;
use App\Support\Payroll\BuildCrewPayrollGenerationPreview;
use Illuminate\Http\JsonResponse;

class CrewPayrollGenerationPreviewController extends Controller
{
    public function __invoke(
        ShowCrewPayrollGenerationPreviewRequest $request,
        PayrollPeriod $payrollPeriod,
        BuildCrewPayrollGenerationPreview $buildPreview,
    ): JsonResponse {
        $companyId = (int) $request->attributes->get('current_company_id');
        abort_unless((int) $payrollPeriod->company_id === $companyId, 404);
        abort_unless($payrollPeriod->isCrew(), 404);

        $excluded = array_values(array_unique(array_map(
            intval(...),
            array_merge(
                $payrollPeriod->excluded_employee_ids ?? [],
                $request->validated('excluded_employee_ids') ?? [],
            ),
        )));

        $preview = $buildPreview->handle($payrollPeriod, $companyId, $excluded);

        return response()->json($preview->toPublicArray());
    }
}
