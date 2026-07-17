<?php

namespace App\Http\Controllers\Organization;

use App\Exceptions\CrewMovementException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Organization\ApproveCrewMovementCorrectionRequest;
use App\Http\Requests\Organization\CancelCrewMovementCorrectionRequest;
use App\Http\Requests\Organization\RejectCrewMovementCorrectionRequest;
use App\Models\CrewMovementCorrection;
use App\Support\CrewMovements\Corrections\ApproveCrewMovementCorrection;
use App\Support\CrewMovements\Corrections\CancelCrewMovementCorrection;
use App\Support\CrewMovements\Corrections\CrewMovementCorrectionAccess;
use App\Support\CrewMovements\Corrections\RejectCrewMovementCorrection;
use App\Support\CrewMovements\Corrections\SendCrewMovementCorrectionDecidedEmail;
use Illuminate\Http\RedirectResponse;

class CrewMovementCorrectionDecisionController extends Controller
{
    public function __construct(
        private readonly ApproveCrewMovementCorrection $approveCorrection,
        private readonly RejectCrewMovementCorrection $rejectCorrection,
        private readonly CancelCrewMovementCorrection $cancelCorrection,
        private readonly SendCrewMovementCorrectionDecidedEmail $sendDecidedEmail,
    ) {}

    public function approve(
        ApproveCrewMovementCorrectionRequest $request,
        CrewMovementCorrection $correction,
    ): RedirectResponse {
        $companyId = (int) $request->attributes->get('current_company_id');
        CrewMovementCorrectionAccess::assertInCompany($correction, $companyId);

        try {
            $correction = $this->approveCorrection->handle(
                $correction,
                $request->user(),
                $companyId,
                $request->validated('decision_notes'),
            );
        } catch (CrewMovementException $exception) {
            return back()->withErrors([
                'correction' => $exception->getMessage(),
            ]);
        }

        try {
            $this->sendDecidedEmail->handle($correction);
        } catch (\Throwable $exception) {
            report($exception);
        }

        return redirect()
            ->route('organization.crew-movement-corrections.show', $correction)
            ->with('success', 'Correction approved and applied.');
    }

    public function reject(
        RejectCrewMovementCorrectionRequest $request,
        CrewMovementCorrection $correction,
    ): RedirectResponse {
        $companyId = (int) $request->attributes->get('current_company_id');
        CrewMovementCorrectionAccess::assertInCompany($correction, $companyId);

        try {
            $correction = $this->rejectCorrection->handle(
                $correction,
                $request->user(),
                $companyId,
                (string) $request->validated('decision_notes'),
            );
        } catch (CrewMovementException $exception) {
            return back()->withErrors([
                'correction' => $exception->getMessage(),
            ]);
        }

        try {
            $this->sendDecidedEmail->handle($correction);
        } catch (\Throwable $exception) {
            report($exception);
        }

        return redirect()
            ->route('organization.crew-movement-corrections.show', $correction)
            ->with('success', 'Correction rejected.');
    }

    public function cancel(
        CancelCrewMovementCorrectionRequest $request,
        CrewMovementCorrection $correction,
    ): RedirectResponse {
        $companyId = (int) $request->attributes->get('current_company_id');
        CrewMovementCorrectionAccess::assertInCompany($correction, $companyId);

        try {
            $correction = $this->cancelCorrection->handle(
                $correction,
                $request->user(),
                $companyId,
                $request->validated('decision_notes'),
            );
        } catch (CrewMovementException $exception) {
            return back()->withErrors([
                'correction' => $exception->getMessage(),
            ]);
        }

        return redirect()
            ->route('organization.crew-movement-corrections.index')
            ->with('success', 'Correction cancelled.');
    }
}
