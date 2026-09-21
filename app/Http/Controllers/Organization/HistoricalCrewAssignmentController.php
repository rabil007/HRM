<?php

namespace App\Http\Controllers\Organization;

use App\Http\Controllers\Controller;
use App\Http\Requests\Organization\PreviewHistoricalCrewAssignmentRequest;
use App\Http\Requests\Organization\StoreHistoricalCrewAssignmentRequest;
use App\Http\Requests\Organization\ValidateHistoricalCrewImportRequest;
use App\Models\CrewAssignment;
use App\Support\CrewMovements\Historical\HistoricalCrewAssignmentService;
use App\Support\CrewMovements\Historical\HistoricalCrewImportPreviewService;
use App\Support\CrewMovements\Historical\HistoricalCrewImportTemplate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class HistoricalCrewAssignmentController extends Controller
{
    public function __construct(
        private readonly HistoricalCrewAssignmentService $historicalService,
        private readonly HistoricalCrewImportTemplate $importTemplate,
        private readonly HistoricalCrewImportPreviewService $importPreview,
    ) {}

    public function preview(PreviewHistoricalCrewAssignmentRequest $request): JsonResponse
    {
        Gate::authorize('createHistorical', CrewAssignment::class);

        $data = $request->toData();

        $preview = $this->historicalService->preview($data, $request->user());

        return response()->json($preview->toArray());
    }

    public function store(StoreHistoricalCrewAssignmentRequest $request): RedirectResponse
    {
        Gate::authorize('createHistorical', CrewAssignment::class);

        $data = $request->toData();

        $assignment = $this->historicalService->create(
            data: $data,
            actorId: $request->user()?->id,
        );

        return redirect()
            ->route('organization.crew-assignments.index')
            ->with('success', "Historical assignment {$assignment->assignment_no} recorded successfully.");
    }

    public function importTemplate(Request $request): BinaryFileResponse
    {
        Gate::authorize('createHistorical', CrewAssignment::class);

        $companyId = (int) $request->attributes->get('current_company_id');
        $user = $request->user();

        abort_unless($user !== null, 403);

        $result = $this->importTemplate->export($companyId, $user);

        return response()
            ->download($result['path'], $result['filename'])
            ->deleteFileAfterSend();
    }

    public function importValidate(ValidateHistoricalCrewImportRequest $request): JsonResponse
    {
        Gate::authorize('createHistorical', CrewAssignment::class);

        $companyId = (int) $request->attributes->get('current_company_id');
        $user = $request->user();

        abort_unless($user !== null, 403);

        try {
            $result = $this->importPreview->preview(
                $companyId,
                $request->file('file'),
                $user,
            );
        } catch (\InvalidArgumentException $exception) {
            throw ValidationException::withMessages([
                'file' => $exception->getMessage(),
            ]);
        }

        return response()->json($result);
    }
}
