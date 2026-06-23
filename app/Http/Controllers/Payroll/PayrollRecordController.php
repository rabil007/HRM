<?php

namespace App\Http\Controllers\Payroll;

use App\Enums\PayrollCategory;
use App\Http\Controllers\Controller;
use App\Models\PayrollRecord;
use App\Support\Pagination\ResolvesPerPage;
use App\Support\Payroll\PayrollRecordIndexResource;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class PayrollRecordController extends Controller
{
    use ResolvesPerPage;

    public function index(Request $request): Response
    {
        $companyId = (int) $request->attributes->get('current_company_id');
        $perPage = $this->resolvePerPage($request);
        $search = trim((string) $request->query('search', ''));
        $category = trim((string) $request->query('category', ''));
        $periodId = trim((string) $request->query('period_id', ''));
        $status = trim((string) $request->query('status', ''));
        $dateFrom = trim((string) $request->query('date_from', ''));
        $dateTo = trim((string) $request->query('date_to', ''));

        $query = PayrollRecord::query()
            ->where('company_id', $companyId)
            ->with(['employee', 'period'])
            ->orderByDesc('id');

        if ($search !== '') {
            $term = '%'.mb_strtolower($search).'%';
            $query->whereHas('employee', function (Builder $employeeQuery) use ($term): void {
                $employeeQuery
                    ->whereRaw('LOWER(name) LIKE ?', [$term])
                    ->orWhereRaw('LOWER(employee_no) LIKE ?', [$term]);
            });
        }

        if (in_array($category, [PayrollCategory::Crew->value, PayrollCategory::Office->value], true)) {
            $query->where('payroll_category', $category);
        }

        if ($periodId !== '' && ctype_digit($periodId)) {
            $query->where('period_id', (int) $periodId);
        }

        if (in_array($status, ['draft', 'approved', 'paid'], true)) {
            $query->where('status', $status);
        }

        if ($this->isValidDate($dateFrom)) {
            $query->whereHas('period', fn (Builder $periodQuery) => $periodQuery->whereDate('end_date', '>=', $dateFrom));
        }

        if ($this->isValidDate($dateTo)) {
            $query->whereHas('period', fn (Builder $periodQuery) => $periodQuery->whereDate('start_date', '<=', $dateTo));
        }

        $paginator = $query
            ->paginate($perPage)
            ->withQueryString();

        return Inertia::render('payroll/records', [
            'records' => collect($paginator->items())
                ->map(fn (PayrollRecord $record) => PayrollRecordIndexResource::toArray($record))
                ->values()
                ->all(),
            'pagination' => $this->paginationMeta($paginator),
            'search' => $search,
            'filters' => [
                'category' => $category,
                'period_id' => $periodId,
                'status' => $status,
                'date_from' => $dateFrom,
                'date_to' => $dateTo,
            ],
            'payroll_categories' => collect(PayrollCategory::cases())
                ->map(fn (PayrollCategory $item) => ['value' => $item->value, 'label' => $item->label()])
                ->values()
                ->all(),
            'status_options' => [
                ['value' => 'draft', 'label' => 'Draft'],
                ['value' => 'approved', 'label' => 'Approved'],
                ['value' => 'paid', 'label' => 'Paid'],
            ],
        ]);
    }

    private function isValidDate(string $value): bool
    {
        if ($value === '') {
            return false;
        }

        $date = \DateTimeImmutable::createFromFormat('Y-m-d', $value);

        return $date !== false && $date->format('Y-m-d') === $value;
    }
}
