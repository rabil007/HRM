<?php

namespace App\Http\Controllers\Attendance;

use App\Http\Controllers\Controller;
use App\Http\Requests\Attendance\StoreLeaveTypeRequest;
use App\Http\Requests\Attendance\UpdateLeaveTypeRequest;
use App\Http\Requests\Attendance\UpdateLeaveTypeStatusRequest;
use App\Models\LeaveType;
use App\Support\Pagination\ResolvesPerPage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class LeaveTypeController extends Controller
{
    use ResolvesPerPage;

    public function index(): Response
    {
        $companyId = (int) request()->attributes->get('current_company_id');
        $perPage = $this->resolvePerPage(request());
        $search = trim((string) request()->query('search', ''));

        $paginator = LeaveType::query()
            ->where('company_id', $companyId)
            ->when($search, function ($query) use ($search) {
                $query->where(function ($inner) use ($search) {
                    $inner->where('name', 'like', "%{$search}%")
                        ->orWhere('code', 'like', "%{$search}%");
                });
            })
            ->orderBy('name')
            ->paginate($perPage)
            ->withQueryString();

        $leaveTypes = $paginator->through(fn (LeaveType $leaveType) => [
            'id' => $leaveType->id,
            'name' => $leaveType->name,
            'code' => $leaveType->code,
            'days_per_year' => $leaveType->days_per_year,
            'carry_forward' => $leaveType->carry_forward,
            'max_carry_days' => $leaveType->max_carry_days,
            'color' => $leaveType->color,
            'status' => $leaveType->status,
        ]);

        return Inertia::render('attendance/types', [
            'leave_types' => $leaveTypes->items(),
            'pagination' => $this->paginationMeta($paginator),
            'search' => $search,
        ]);
    }

    public function store(StoreLeaveTypeRequest $request): RedirectResponse
    {
        $data = $this->normalizeLeaveTypeData($request->validated());
        $data['company_id'] = (int) $request->attributes->get('current_company_id');

        LeaveType::query()->create($data);

        return redirect()
            ->route('attendance.types.index')
            ->with('success', 'Attendance type created successfully.');
    }

    public function update(UpdateLeaveTypeRequest $request, LeaveType $leaveType): RedirectResponse
    {
        $companyId = (int) $request->attributes->get('current_company_id');
        abort_unless((int) $leaveType->company_id === $companyId, 404);

        $leaveType->update($this->normalizeLeaveTypeData($request->validated()));

        return redirect()
            ->route('attendance.types.index')
            ->with('success', 'Attendance type updated successfully.');
    }

    public function updateStatus(UpdateLeaveTypeStatusRequest $request, LeaveType $leaveType): RedirectResponse
    {
        $companyId = (int) $request->attributes->get('current_company_id');
        abort_unless((int) $leaveType->company_id === $companyId, 404);

        $leaveType->update([
            'status' => $request->validated('status'),
        ]);

        return redirect()
            ->route('attendance.types.index')
            ->with('success', 'Attendance type status updated successfully.');
    }

    public function destroy(LeaveType $leaveType): RedirectResponse
    {
        $companyId = (int) request()->attributes->get('current_company_id');
        abort_unless((int) $leaveType->company_id === $companyId, 404);

        if (DB::table('leave_balances')->where('leave_type_id', $leaveType->id)->exists()) {
            return redirect()
                ->route('attendance.types.index')
                ->withErrors(['leave_type' => 'This attendance type cannot be deleted because it is used in leave balances.']);
        }

        if (DB::table('leave_requests')->where('leave_type_id', $leaveType->id)->exists()) {
            return redirect()
                ->route('attendance.types.index')
                ->withErrors(['leave_type' => 'This attendance type cannot be deleted because it is used in leave requests.']);
        }

        $leaveType->delete();

        return redirect()
            ->route('attendance.types.index')
            ->with('success', 'Attendance type deleted successfully.');
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function normalizeLeaveTypeData(array $data): array
    {
        $data['carry_forward'] = (bool) ($data['carry_forward'] ?? false);

        if (($data['color'] ?? null) === '') {
            $data['color'] = null;
        }

        return $data;
    }
}
