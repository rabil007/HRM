<?php

namespace App\Http\Controllers\Settings\MasterData;

use App\Http\Controllers\Concerns\ReturnsQuickCreateJson;
use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\MasterData\ImportVesselsRequest;
use App\Http\Requests\Settings\MasterData\StoreVesselRequest;
use App\Http\Requests\Settings\MasterData\UpdateVesselRequest;
use App\Models\EmployeeDeployment;
use App\Models\EmployeeSeaService;
use App\Models\Vessel;
use App\Models\VesselType;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

class VesselController extends Controller
{
    use ReturnsQuickCreateJson;

    public function index(): InertiaResponse
    {
        $vesselTypes = VesselType::query()
            ->orderBy('name')
            ->get(['id', 'name']);

        $vessels = Vessel::query()
            ->with(['vesselType:id,name'])
            ->orderBy('name')
            ->get([
                'id',
                'name',
                'vessel_type_id',
                'grt',
                'bhp',
                'is_active',
            ]);

        return Inertia::render('settings/master-data/vessels', [
            'vessels' => $vessels,
            'vessel_types' => $vesselTypes,
        ]);
    }

    public function store(StoreVesselRequest $request): JsonResponse|RedirectResponse
    {
        $data = $request->validated();
        $data['is_active'] = $data['is_active'] ?? true;

        return $this->createOrReturnExistingQuickCreate(
            $request,
            Vessel::class,
            $data,
            redirect()->route('settings.master-data.vessels.index'),
        );
    }

    public function update(UpdateVesselRequest $request, Vessel $vessel): RedirectResponse
    {
        $vessel->update($request->validated());

        return redirect()->route('settings.master-data.vessels.index');
    }

    public function destroy(Vessel $vessel): RedirectResponse
    {
        if (EmployeeSeaService::query()->where('vessel_id', $vessel->id)->exists()) {
            return redirect()
                ->route('settings.master-data.vessels.index')
                ->withErrors([
                    'name' => 'This vessel is used on employee sea service records and cannot be deleted.',
                ]);
        }

        if (EmployeeDeployment::query()->where('vessel_id', $vessel->id)->exists()) {
            return redirect()
                ->route('settings.master-data.vessels.index')
                ->withErrors([
                    'name' => 'This vessel is used on crew deployment records and cannot be deleted.',
                ]);
        }

        $vessel->delete();

        return redirect()->route('settings.master-data.vessels.index');
    }

    public function importTemplate(): Response
    {
        $csv = "name,vessel_type,grt,bhp,is_active\nADNOC 951,H/LIFT,4500,12000,yes\n";

        return response($csv, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="vessels-import-template.csv"',
        ]);
    }

    public function import(ImportVesselsRequest $request): RedirectResponse
    {
        $uploaded = $request->file('file');
        $path = $uploaded->getRealPath() ?: $uploaded->path();
        $handle = fopen((string) $path, 'r');

        if ($handle === false) {
            return redirect()
                ->route('settings.master-data.vessels.index')
                ->withErrors(['file' => 'Could not read the uploaded file.']);
        }

        $header = fgetcsv($handle);
        if (! is_array($header) || count($header) === 0) {
            fclose($handle);

            return redirect()
                ->route('settings.master-data.vessels.index')
                ->withErrors(['file' => 'The CSV file is empty.']);
        }

        $map = [];
        foreach ($header as $index => $cell) {
            $key = mb_strtolower(trim((string) $cell));
            if (in_array($key, ['name', 'vessel', 'vessel name', 'vessel_name'], true)) {
                $map['name'] = (int) $index;
            }
            if (in_array($key, ['vessel_type', 'vessel type', 'type'], true)) {
                $map['vessel_type'] = (int) $index;
            }
            if (in_array($key, ['grt', 'gross tonnage', 'gross_tonnage'], true)) {
                $map['grt'] = (int) $index;
            }
            if (in_array($key, ['bhp', 'brake horsepower', 'horsepower'], true)) {
                $map['bhp'] = (int) $index;
            }
            if (in_array($key, ['active', 'is_active', 'status', 'enabled'], true)) {
                $map['active'] = (int) $index;
            }
        }

        if (! isset($map['name'], $map['vessel_type'])) {
            fclose($handle);

            return redirect()
                ->route('settings.master-data.vessels.index')
                ->withErrors(['file' => 'The CSV must include name and vessel_type columns.']);
        }

        $vesselTypes = VesselType::query()->get(['id', 'name']);
        $imported = 0;
        $emptyNames = 0;
        $unknownTypes = 0;

        while (($row = fgetcsv($handle)) !== false) {
            if (! is_array($row)) {
                continue;
            }

            $name = trim((string) ($row[$map['name']] ?? ''));
            if ($name === '') {
                $emptyNames++;

                continue;
            }

            $typeName = trim((string) ($row[$map['vessel_type']] ?? ''));
            $vesselType = $vesselTypes->first(fn (VesselType $type) => mb_strtolower($type->name) === mb_strtolower($typeName));

            if ($vesselType === null) {
                $unknownTypes++;

                continue;
            }

            $grt = null;
            if (isset($map['grt'])) {
                $grtRaw = trim((string) ($row[$map['grt']] ?? ''));
                if ($grtRaw !== '' && is_numeric($grtRaw)) {
                    $grt = (float) $grtRaw;
                }
            }

            $bhp = null;
            if (isset($map['bhp'])) {
                $bhpRaw = trim((string) ($row[$map['bhp']] ?? ''));
                if ($bhpRaw !== '' && is_numeric($bhpRaw)) {
                    $bhp = (int) $bhpRaw;
                }
            }

            $active = true;
            if (isset($map['active'])) {
                $v = mb_strtolower(trim((string) ($row[$map['active']] ?? '')));
                $active = $v === '' || in_array($v, ['1', 'yes', 'true', 'y', 'active'], true);
            }

            Vessel::query()->updateOrCreate(
                ['name' => $name],
                [
                    'vessel_type_id' => $vesselType->id,
                    'grt' => $grt,
                    'bhp' => $bhp,
                    'is_active' => $active,
                ],
            );
            $imported++;

            if ($imported > 2000) {
                break;
            }
        }

        fclose($handle);

        if ($imported === 0) {
            return redirect()
                ->route('settings.master-data.vessels.index')
                ->withErrors([
                    'file' => $emptyNames > 0
                        ? "No rows were imported. {$emptyNames} row(s) had an empty name."
                        : ($unknownTypes > 0
                            ? "No rows were imported. {$unknownTypes} row(s) had an unknown vessel type."
                            : 'No rows were imported. Ensure each row has a name and vessel type.'),
                ]);
        }

        $message = "Imported {$imported} vessel row(s).";
        if ($unknownTypes > 0) {
            $message .= " Skipped {$unknownTypes} row(s) with unknown vessel types.";
        }

        return redirect()
            ->route('settings.master-data.vessels.index')
            ->with('success', $message);
    }
}
