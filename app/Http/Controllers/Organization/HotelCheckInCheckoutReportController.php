<?php

namespace App\Http\Controllers\Organization;

use App\Enums\CrewAccommodationStatus;
use App\Enums\CrewAccommodationStayType;
use App\Exports\HotelCheckInCheckoutExport;
use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\Hotel;
use App\Models\Rank;
use App\Models\RoomType;
use App\Support\Pagination\ResolvesPerPage;
use App\Support\Reports\HotelCheckInCheckoutFilters;
use App\Support\Reports\HotelCheckInCheckoutPagePermissions;
use App\Support\Reports\HotelCheckInCheckoutQuery;
use App\Support\Settings\CompanyTimezone;
use App\Support\Vessels\ResolvesCompanyVessels;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Maatwebsite\Excel\Excel as ExcelWriter;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class HotelCheckInCheckoutReportController extends Controller
{
    use ResolvesPerPage;

    public function index(Request $request): Response
    {
        $companyId = (int) $request->attributes->get('current_company_id');
        $filters = HotelCheckInCheckoutFilters::fromRequest($request);
        $timezone = $this->companyTimezone($companyId);
        $query = new HotelCheckInCheckoutQuery($companyId, $filters, $timezone, $request->user());
        $paginator = $query->paginate($this->resolvePerPage($request, default: 25, allowed: [25, 50, 100]));

        return Inertia::render('organization/reports/hotel-checkin-checkout/index', [
            'stays' => $paginator->items(),
            'pagination' => $this->paginationMeta($paginator),
            'summary' => $query->summary(),
            'filters' => $filters->toArray(),
            'filter_options' => [
                'hotels' => Hotel::query()
                    ->forCompany($companyId)
                    ->where('is_active', true)
                    ->orderBy('name')
                    ->get(['id', 'name'])
                    ->map(fn (Hotel $hotel): array => ['id' => (int) $hotel->id, 'name' => (string) $hotel->name])
                    ->values()
                    ->all(),
                'room_types' => RoomType::query()
                    ->where('company_id', $companyId)
                    ->where('is_active', true)
                    ->orderBy('name')
                    ->get(['id', 'hotel_id', 'name'])
                    ->map(fn (RoomType $roomType): array => [
                        'id' => (int) $roomType->id,
                        'hotel_id' => $roomType->hotel_id !== null ? (int) $roomType->hotel_id : null,
                        'name' => (string) $roomType->name,
                    ])
                    ->values()
                    ->all(),
                'stay_types' => collect(CrewAccommodationStayType::cases())
                    ->map(fn (CrewAccommodationStayType $type): array => [
                        'value' => $type->value,
                        'label' => $type->label(),
                    ])
                    ->all(),
                'stay_statuses' => [
                    ['value' => 'currently_checked_in', 'label' => 'Currently Checked In'],
                    ['value' => 'check_in_today', 'label' => 'Check-In Today'],
                    ['value' => 'checking_out_today', 'label' => 'Checking Out Today'],
                    ['value' => 'upcoming', 'label' => 'Upcoming'],
                    ['value' => 'checked_out', 'label' => 'Checked Out'],
                ],
                'accommodation_statuses' => collect(CrewAccommodationStatus::cases())
                    ->map(fn (CrewAccommodationStatus $status): array => [
                        'value' => $status->value,
                        'label' => $status->label(),
                    ])
                    ->all(),
                'vessels' => ResolvesCompanyVessels::activeOptions($companyId),
                'ranks' => $this->activeOptions(Rank::query()),
                'clients' => $this->activeOptions(Client::query()),
            ],
            'company_today' => Carbon::now($timezone)->toDateString(),
            'can' => HotelCheckInCheckoutPagePermissions::for($request->user()),
        ]);
    }

    public function export(Request $request): BinaryFileResponse
    {
        $companyId = (int) $request->attributes->get('current_company_id');
        $filters = HotelCheckInCheckoutFilters::fromRequest($request);
        $timezone = $this->companyTimezone($companyId);
        $query = new HotelCheckInCheckoutQuery($companyId, $filters, $timezone, $request->user());
        $export = HotelCheckInCheckoutExport::forQuery($query->exportQuery(), $timezone);
        $filename = 'hotel-checkin-checkout-report-'.Carbon::now($timezone)->toDateString();
        $format = strtolower((string) $request->query('format', 'xlsx'));

        if ($format === 'csv') {
            return Excel::download($export, "{$filename}.csv", ExcelWriter::CSV, [
                'Content-Type' => 'text/csv; charset=UTF-8',
            ]);
        }

        return Excel::download($export, "{$filename}.xlsx", ExcelWriter::XLSX);
    }

    /**
     * @param  Builder<Model>  $query
     * @return list<array{id: int, name: string}>
     */
    private function activeOptions(Builder $query): array
    {
        return $query
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn ($option): array => ['id' => (int) $option->id, 'name' => (string) $option->name])
            ->values()
            ->all();
    }

    private function companyTimezone(int $companyId): string
    {
        return CompanyTimezone::forCompanyId($companyId);
    }
}
