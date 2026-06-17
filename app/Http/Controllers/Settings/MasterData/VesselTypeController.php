<?php

namespace App\Http\Controllers\Settings\MasterData;

use App\Http\Controllers\Concerns\ReturnsQuickCreateJson;
use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\MasterData\ImportVesselTypesRequest;
use App\Http\Requests\Settings\MasterData\StoreVesselTypeRequest;
use App\Http\Requests\Settings\MasterData\UpdateVesselTypeRequest;
use App\Models\EmployeeSeaService;
use App\Models\VesselType;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

class VesselTypeController extends Controller
{
    use ReturnsQuickCreateJson;

    public function index(): InertiaResponse
    {
        $vesselTypes = VesselType::query()
            ->orderBy('name')
            ->get(['id', 'name', 'is_active']);

        return Inertia::render('settings/master-data/vessel-types', [
            'vessel_types' => $vesselTypes,
        ]);
    }

    public function store(StoreVesselTypeRequest $request): JsonResponse|RedirectResponse
    {
        $data = $request->validated();
        $data['is_active'] = $data['is_active'] ?? true;

        return $this->createOrReturnExistingQuickCreate(
            $request,
            VesselType::class,
            $data,
            redirect()->route('settings.master-data.vessel-types.index'),
        );
    }

    public function update(UpdateVesselTypeRequest $request, VesselType $vesselType): RedirectResponse
    {
        $vesselType->update($request->validated());

        return redirect()->route('settings.master-data.vessel-types.index');
    }

    public function destroy(VesselType $vesselType): RedirectResponse
    {
        if (EmployeeSeaService::query()->where('vessel_type_id', $vesselType->id)->exists()) {
            return redirect()
                ->route('settings.master-data.vessel-types.index')
                ->withErrors([
                    'name' => 'This vessel type is used on employee sea service records and cannot be deleted.',
                ]);
        }

        if ($vesselType->vessels()->exists()) {
            return redirect()
                ->route('settings.master-data.vessel-types.index')
                ->withErrors([
                    'name' => 'This vessel type is used by vessels in master data and cannot be deleted.',
                ]);
        }

        $vesselType->delete();

        return redirect()->route('settings.master-data.vessel-types.index');
    }

    public function importTemplate(): Response
    {
        $csv = "name,is_active\nOSV Aurora,yes\nOffshore Supply Vessel 02,yes\n";

        return response($csv, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="vessel-types-import-template.csv"',
        ]);
    }

    public function import(ImportVesselTypesRequest $request): RedirectResponse
    {
        $uploaded = $request->file('file');
        $path = $uploaded->getRealPath() ?: $uploaded->path();
        $handle = fopen((string) $path, 'r');

        if ($handle === false) {
            return redirect()
                ->route('settings.master-data.vessel-types.index')
                ->withErrors(['file' => 'Could not read the uploaded file.']);
        }

        $header = fgetcsv($handle);
        if (! is_array($header) || count($header) === 0) {
            fclose($handle);

            return redirect()
                ->route('settings.master-data.vessel-types.index')
                ->withErrors(['file' => 'The CSV file is empty.']);
        }

        $map = [];
        foreach ($header as $index => $cell) {
            $key = mb_strtolower(trim((string) $cell));
            if (in_array($key, ['name', 'vessel', 'vessel name', 'vessel type', 'type', 'title'], true)) {
                $map['name'] = (int) $index;
            }
            if (in_array($key, ['active', 'is_active', 'status', 'enabled'], true)) {
                $map['active'] = (int) $index;
            }
        }

        if (! isset($map['name'])) {
            fclose($handle);

            return redirect()
                ->route('settings.master-data.vessel-types.index')
                ->withErrors(['file' => 'The CSV must include a name column.']);
        }

        $imported = 0;
        $emptyNames = 0;

        while (($row = fgetcsv($handle)) !== false) {
            if (! is_array($row)) {
                continue;
            }

            $name = trim((string) ($row[$map['name']] ?? ''));
            if ($name === '') {
                $emptyNames++;

                continue;
            }

            $active = true;
            if (isset($map['active'])) {
                $v = mb_strtolower(trim((string) ($row[$map['active']] ?? '')));
                $active = $v === '' || in_array($v, ['1', 'yes', 'true', 'y', 'active'], true);
            }

            VesselType::query()->updateOrCreate(
                ['name' => $name],
                ['is_active' => $active],
            );
            $imported++;

            if ($imported > 2000) {
                break;
            }
        }

        fclose($handle);

        if ($imported === 0) {
            return redirect()
                ->route('settings.master-data.vessel-types.index')
                ->withErrors([
                    'file' => $emptyNames > 0
                        ? "No rows were imported. {$emptyNames} row(s) had an empty name."
                        : 'No rows were imported. Ensure each row has a name.',
                ]);
        }

        return redirect()
            ->route('settings.master-data.vessel-types.index')
            ->with('success', "Imported {$imported} vessel type row(s).");
    }
}
