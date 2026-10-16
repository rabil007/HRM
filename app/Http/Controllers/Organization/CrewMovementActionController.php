<?php

namespace App\Http\Controllers\Organization;

use App\Enums\CrewMovementAction;
use App\Exceptions\CrewMovementException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Organization\PerformCrewMovementActionRequest;
use App\Models\CrewAssignment;
use App\Support\CrewMovements\CrewAssignmentAccess;
use App\Support\CrewMovements\CrewMovementService;
use App\Support\CrewMovements\Scheduling\ResolveCrewMovementTiming;
use App\Support\CrewMovements\Scheduling\ScheduleCrewMovement;
use App\Support\Settings\PlatformDateFormatter;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class CrewMovementActionController extends Controller
{
    public function __construct(private CrewMovementService $service) {}

    public function __invoke(
        PerformCrewMovementActionRequest $request,
        CrewAssignment $assignment,
        ScheduleCrewMovement $scheduler,
    ) {
        $companyId = (int) $request->attributes->get('current_company_id');
        CrewAssignmentAccess::assertInCompany($assignment, $companyId, $request->user());

        $validated = $request->validated();
        $actionValue = (string) $validated['action'];

        $action = CrewMovementAction::tryFrom($actionValue);
        if ($action === null) {
            throw ValidationException::withMessages(['action' => 'Invalid action.']);
        }

        if ($action === CrewMovementAction::CancelAssignment) {
            Gate::authorize('cancel', $assignment);
        } else {
            Gate::authorize('performMovement', $assignment);
        }

        $payload = collect($validated)->except(['action', 'mode'])->all();
        $occurredAt = isset($payload['occurred_at']) ? (string) $payload['occurred_at'] : null;

        // Backend re-decides immediate vs schedule from the timestamp. Never trust client mode.
        if (ResolveCrewMovementTiming::shouldSchedule($action, $occurredAt, $companyId)) {
            Gate::authorize('scheduleMovement', $assignment);

            $payload['scheduled_at'] = $occurredAt;

            try {
                $schedule = $scheduler->handle(
                    $companyId,
                    $assignment,
                    $action,
                    $payload,
                    $request->user(),
                );
            } catch (CrewMovementException $e) {
                throw ValidationException::withMessages(['error' => $e->getMessage()]);
            }

            return redirect()
                ->route('organization.crew-assignments.show', $assignment)
                ->with('success', sprintf(
                    '%s scheduled for %s.',
                    $schedule->movement_action->label(),
                    PlatformDateFormatter::make()->dateTime(
                        $schedule->scheduled_at?->timezone($schedule->scheduled_timezone),
                        null,
                    ),
                ));
        }

        try {
            $result = $this->service->perform(
                $companyId,
                $assignment->id,
                $action,
                collect($payload)->except([
                    'check_in_date_auto_synced',
                    'check_out_date_auto_synced',
                    'source_check_out_date_auto_synced',
                ])->all(),
                $request->user()?->id,
            );

            return redirect()
                ->route('organization.crew-assignments.show', $result)
                ->with('success', sprintf('%s completed successfully.', $action->label()));
        } catch (CrewMovementException $e) {
            throw ValidationException::withMessages(['error' => $e->getMessage()]);
        }
    }
}
