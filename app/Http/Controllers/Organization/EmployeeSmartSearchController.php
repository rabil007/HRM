<?php

namespace App\Http\Controllers\Organization;

use App\Http\Controllers\Controller;
use App\Http\Requests\Organization\Employee\InterpretEmployeeSmartSearchRequest;
use App\Services\EmployeeSmartSearchInterpreter;
use App\Services\Settings\AiSettingsService;
use App\Support\Employees\EmployeeSmartSearchPromptGuard;
use App\Support\Employees\EmployeeSmartSearchResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;

class EmployeeSmartSearchController extends Controller
{
    public function __invoke(
        InterpretEmployeeSmartSearchRequest $request,
        AiSettingsService $aiSettings,
        EmployeeSmartSearchInterpreter $interpreter,
        EmployeeSmartSearchResolver $resolver,
    ): JsonResponse {
        abort_unless(
            $aiSettings->isSmartSearchEnabled(),
            403,
            'Employee smart search is not enabled.',
        );

        $prompt = $request->prompt();

        if (EmployeeSmartSearchPromptGuard::shouldBlock($prompt)) {
            throw ValidationException::withMessages([
                'prompt' => EmployeeSmartSearchPromptGuard::MESSAGE,
            ]);
        }

        $companyId = (int) $request->attributes->get('current_company_id');

        return response()->json(
            $resolver->resolve($companyId, $interpreter->interpret($prompt))->toArray(),
        );
    }
}
