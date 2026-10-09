<?php

namespace App\Http\Controllers\Organization;

use App\Exceptions\CrewMovementException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Organization\CancelCrewScheduledMovementRequest;
use App\Http\Requests\Organization\StoreCrewScheduledMovementRequest;
use App\Http\Requests\Organization\UpdateCrewScheduledMovementRequest;
use App\Models\CrewAssignment;
use App\Models\CrewScheduledMovement;
use App\Support\CrewMovements\CrewAssignmentAccess;
use App\Support\CrewMovements\Scheduling\CancelCrewScheduledMovement;
use App\Support\CrewMovements\Scheduling\CrewScheduledMovementAccess;
use App\Support\CrewMovements\Scheduling\CrewScheduledMovementIndexQuery;
use App\Support\CrewMovements\Scheduling\ScheduleCrewMovement;
use App\Support\CrewMovements\Scheduling\UpdateCrewScheduledMovement;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class CrewScheduledMovementController extends Controller
{
    public function index(Request $request)
    {
        abort_unless(CrewScheduledMovementAccess::canView($request->user()), 403);

        $companyId = (int) $request->attributes->get('current_company_id');

        $filters = [
            'status' => $request->query('status'),
            'movement_action' => $request->query('movement_action'),
            'employee_id' => $request->integer('employee_id') ?: null,
            'vessel_id' => $request->integer('vessel_id') ?: null,
            'search' => $request->query('search'),
            'scheduled_from' => $request->query('scheduled_from'),
            'scheduled_to' => $request->query('scheduled_to'),
            'tab' => $request->query('tab', 'upcoming'),
        ];

        $page = app(CrewScheduledMovementIndexQuery::class)->paginate(
            $companyId,
            $request->user(),
            $filters,
        );

        return Inertia::render('organization/crew-scheduled-movements/index', [
            ...$page,
            'can' => [
                'view' => CrewScheduledMovementAccess::canView($request->user()),
                'schedule' => CrewScheduledMovementAccess::canSchedule($request->user()),
                'manage' => CrewScheduledMovementAccess::canManage($request->user()),
            ],
        ]);
    }

    public function store(
        StoreCrewScheduledMovementRequest $request,
        CrewAssignment $assignment,
        ScheduleCrewMovement $scheduler,
    ) {
        $companyId = (int) $request->attributes->get('current_company_id');
        CrewAssignmentAccess::assertInCompany($assignment, $companyId, $request->user());
        Gate::authorize('scheduleMovement', $assignment);

        try {
            $schedule = $scheduler->handle(
                $companyId,
                $assignment,
                $request->movementAction(),
                $request->schedulePayload(),
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
                $schedule->scheduled_at?->timezone($schedule->scheduled_timezone)->format('d M Y H:i'),
            ));
    }

    public function update(
        UpdateCrewScheduledMovementRequest $request,
        CrewScheduledMovement $scheduledMovement,
        UpdateCrewScheduledMovement $updater,
    ) {
        $companyId = (int) $request->attributes->get('current_company_id');
        CrewScheduledMovementAccess::assertInCompany($scheduledMovement, $companyId, $request->user());

        try {
            $schedule = $updater->handle(
                $companyId,
                $scheduledMovement,
                $request->updatePayload(),
                $request->user(),
            );
        } catch (CrewMovementException $e) {
            throw ValidationException::withMessages(['error' => $e->getMessage()]);
        }

        return redirect()
            ->route('organization.crew-assignments.show', $schedule->crew_assignment_id)
            ->with('success', sprintf(
                'Scheduled movement updated for %s.',
                $schedule->scheduled_at?->timezone($schedule->scheduled_timezone)->format('d M Y H:i'),
            ));
    }

    public function cancel(
        CancelCrewScheduledMovementRequest $request,
        CrewScheduledMovement $scheduledMovement,
        CancelCrewScheduledMovement $canceller,
    ) {
        $companyId = (int) $request->attributes->get('current_company_id');
        CrewScheduledMovementAccess::assertInCompany($scheduledMovement, $companyId, $request->user());

        try {
            $schedule = $canceller->handle(
                $companyId,
                $scheduledMovement,
                $request->user(),
                $request->validated('reason'),
            );
        } catch (CrewMovementException $e) {
            throw ValidationException::withMessages(['error' => $e->getMessage()]);
        }

        return redirect()
            ->route('organization.crew-assignments.show', $schedule->crew_assignment_id)
            ->with('success', 'Scheduled movement cancelled.');
    }
}
