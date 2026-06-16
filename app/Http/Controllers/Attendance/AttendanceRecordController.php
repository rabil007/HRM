<?php

namespace App\Http\Controllers\Attendance;

use App\Exports\AttendanceRecordsExport;
use App\Http\Controllers\Controller;
use App\Http\Requests\Attendance\StoreAttendanceRecordRequest;
use App\Http\Requests\Attendance\UpdateAttendanceRecordRequest;
use App\Models\AttendanceRecord;
use App\Models\Employee;
use App\Support\Attendance\AttendanceRecordVisibility;
use App\Support\Pagination\ResolvesPerPage;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Maatwebsite\Excel\Excel as ExcelWriter;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class AttendanceRecordController extends Controller
{
    use ResolvesPerPage;

    public function __construct(
        private AttendanceRecordVisibility $visibility,
    ) {}

    public function index(Request $request): Response
    {
        $companyId = (int) $request->attributes->get('current_company_id');
        $user = $request->user();
        $perPage = $this->resolvePerPage($request);
        $search = trim((string) $request->query('search', ''));

        $filters = $this->filtersFromRequest($request, $search);

        $paginator = $this->filteredRecordsQuery($request, $filters)
            ->paginate($perPage)
            ->withQueryString();

        $canManageAll = $this->visibility->canManageAll($user);
        $linkedEmployeeId = $this->visibility->linkedEmployeeId($user, $companyId);

        $employeesQuery = Employee::query()
            ->where('company_id', $companyId)
            ->where('status', 'active')
            ->orderBy('name');

        if (! $canManageAll) {
            $employeesQuery->when(
                $linkedEmployeeId !== null,
                fn ($query) => $query->whereKey($linkedEmployeeId),
                fn ($query) => $query->whereRaw('1 = 0'),
            );
        }

        return Inertia::render('attendance/records', [
            'records' => $paginator->getCollection()
                ->map(fn (AttendanceRecord $record) => $this->serializeRecord($record))
                ->values()
                ->all(),
            'pagination' => $this->paginationMeta($paginator),
            'search' => $search,
            'filters' => $filters,
            'employees' => $employeesQuery->get(['id', 'employee_no', 'name']),
            'linked_employee_id' => $linkedEmployeeId,
            'status_options' => array_map(
                fn (string $value): array => ['value' => $value, 'label' => ucfirst(str_replace('_', ' ', $value))],
                AttendanceRecord::statusOptions(),
            ),
            'source_options' => array_map(
                fn (string $value): array => ['value' => $value, 'label' => ucfirst($value)],
                AttendanceRecord::sourceOptions(),
            ),
            'can' => [
                'create' => $user?->can('attendance.records.create') ?? false,
                'update' => $user?->can('attendance.records.update') ?? false,
                'delete' => $user?->can('attendance.records.delete') ?? false,
                'manage' => $canManageAll,
            ],
        ]);
    }

    public function store(StoreAttendanceRecordRequest $request): RedirectResponse
    {
        $data = $request->validated();
        $companyId = (int) $request->attributes->get('current_company_id');

        $hoursWorked = $data['hours_worked'] ?? AttendanceRecord::calculateHoursWorked(
            isset($data['clock_in']) ? Carbon::parse($data['clock_in']) : null,
            isset($data['clock_out']) ? Carbon::parse($data['clock_out']) : null,
        );

        AttendanceRecord::query()->create([
            'company_id' => $companyId,
            'employee_id' => $data['employee_id'],
            'date' => $data['date'],
            'clock_in' => $data['clock_in'] ?? null,
            'clock_out' => $data['clock_out'] ?? null,
            'hours_worked' => $hoursWorked,
            'overtime_hours' => $data['overtime_hours'] ?? 0,
            'late_minutes' => $data['late_minutes'] ?? 0,
            'source' => $data['source'] ?? AttendanceRecord::SOURCE_MANUAL,
            'status' => $data['status'],
            'notes' => $data['notes'] ?? null,
        ]);

        return redirect()
            ->route('attendance.records.index', $request->only(['search', 'date_from', 'date_to', 'employee_id', 'status', 'source', 'page', 'per_page']))
            ->with('success', 'Attendance record created successfully.');
    }

    public function update(UpdateAttendanceRecordRequest $request, AttendanceRecord $attendanceRecord): RedirectResponse
    {
        $companyId = (int) $request->attributes->get('current_company_id');
        $this->visibility->assertCanAccess($attendanceRecord, $request->user(), $companyId);

        $data = $request->validated();

        $hoursWorked = $data['hours_worked'] ?? AttendanceRecord::calculateHoursWorked(
            isset($data['clock_in']) ? Carbon::parse($data['clock_in']) : null,
            isset($data['clock_out']) ? Carbon::parse($data['clock_out']) : null,
        );

        $attendanceRecord->update([
            'employee_id' => $data['employee_id'],
            'date' => $data['date'],
            'clock_in' => $data['clock_in'] ?? null,
            'clock_out' => $data['clock_out'] ?? null,
            'hours_worked' => $hoursWorked,
            'overtime_hours' => $data['overtime_hours'] ?? 0,
            'late_minutes' => $data['late_minutes'] ?? 0,
            'source' => AttendanceRecord::SOURCE_MANUAL,
            'status' => $data['status'],
            'notes' => $data['notes'] ?? null,
        ]);

        return redirect()
            ->route('attendance.records.index', $request->only(['search', 'date_from', 'date_to', 'employee_id', 'status', 'source', 'page', 'per_page']))
            ->with('success', 'Attendance record updated successfully.');
    }

    public function export(Request $request): BinaryFileResponse
    {
        $search = trim((string) $request->query('search', ''));
        $filters = $this->filtersFromRequest($request, $search);
        $records = $this->filteredRecordsQuery($request, $filters)->get();

        $filename = sprintf(
            'attendance-records_%s_to_%s.xlsx',
            $filters['date_from'],
            $filters['date_to'],
        );

        return Excel::download(
            new AttendanceRecordsExport($records),
            $filename,
            ExcelWriter::XLSX,
        );
    }

    public function destroy(Request $request, AttendanceRecord $attendanceRecord): RedirectResponse
    {
        $companyId = (int) $request->attributes->get('current_company_id');
        $this->visibility->assertCanAccess($attendanceRecord, $request->user(), $companyId);

        abort_unless($request->user()?->can('attendance.records.delete') ?? false, 403);

        $attendanceRecord->delete();

        return redirect()
            ->route('attendance.records.index', $request->only(['search', 'date_from', 'date_to', 'employee_id', 'status', 'source', 'page', 'per_page']))
            ->with('success', 'Attendance record deleted successfully.');
    }

    /**
     * @return array{search: string, date_from: string, date_to: string, employee_id: string, status: string, source: string}
     */
    private function filtersFromRequest(Request $request, string $search = ''): array
    {
        $status = $request->string('status')->toString();

        if ($status !== '' && ! in_array($status, AttendanceRecord::statusOptions(), true)) {
            $status = '';
        }

        $source = $request->string('source')->toString();

        if ($source !== '' && ! in_array($source, AttendanceRecord::sourceOptions(), true)) {
            $source = '';
        }

        $dateFrom = $request->string('date_from')->toString();
        $dateTo = $request->string('date_to')->toString();

        if ($dateFrom === '' && $dateTo === '') {
            $dateFrom = now()->startOfMonth()->toDateString();
            $dateTo = now()->endOfMonth()->toDateString();
        }

        return [
            'search' => $search,
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
            'employee_id' => $request->string('employee_id')->toString(),
            'status' => $status,
            'source' => $source,
        ];
    }

    /**
     * @param  array{search: string, date_from: string, date_to: string, employee_id: string, status: string, source: string}  $filters
     * @return Builder<AttendanceRecord>
     */
    private function filteredRecordsQuery(Request $request, array $filters): Builder
    {
        $companyId = (int) $request->attributes->get('current_company_id');

        return AttendanceRecord::query()
            ->with(['employee:id,company_id,employee_no,name'])
            ->where('company_id', $companyId)
            ->tap(fn ($query) => $this->visibility->applyIndexScope($query, $request->user(), $companyId))
            ->filtered($filters)
            ->orderByDesc('date')
            ->orderByDesc('id');
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeRecord(AttendanceRecord $record): array
    {
        return [
            'id' => $record->id,
            'employee' => $record->employee ? [
                'id' => $record->employee->id,
                'employee_no' => $record->employee->employee_no,
                'name' => $record->employee->name,
            ] : null,
            'date' => $record->date?->toDateString(),
            'clock_in' => $record->clock_in?->toIso8601String(),
            'clock_out' => $record->clock_out?->toIso8601String(),
            'hours_worked' => $record->hours_worked,
            'overtime_hours' => $record->overtime_hours,
            'late_minutes' => $record->late_minutes,
            'source' => $record->source,
            'status' => $record->status,
            'notes' => $record->notes,
        ];
    }
}
