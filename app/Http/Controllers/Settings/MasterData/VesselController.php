<?php

namespace App\Http\Controllers\Settings\MasterData;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\MasterData\ImportVesselsRequest;
use App\Http\Requests\Settings\MasterData\StoreVesselRequest;
use App\Http\Requests\Settings\MasterData\UpdateVesselRequest;
use App\Models\Vessel;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

class VesselController extends Controller
{
    public function index(): InertiaResponse
    {
        $vessels = Vessel::query()
            ->orderBy('name')
            ->get(['id', 'name', 'is_active']);

        return Inertia::render('settings/master-data/vessels', [
            'vessels' => $vessels,
        ]);
    }

    public function store(StoreVesselRequest $request): RedirectResponse
    {
        $data = $request->validated();
        $data['is_active'] = $data['is_active'] ?? true;

        Vessel::query()->create($data);

        return redirect()->route('settings.master-data.vessels.index');
    }

    public function update(UpdateVesselRequest $request, Vessel $vessel): RedirectResponse
    {
        $vessel->update($request->validated());

        return redirect()->route('settings.master-data.vessels.index');
    }

    public function destroy(Vessel $vessel): RedirectResponse
    {
        $vessel->delete();

        return redirect()->route('settings.master-data.vessels.index');
    }

    public function importTemplate(): Response
    {
        $csv = "name,is_active\nOSV Aurora,yes\nOffshore Supply Vessel 02,yes\n";

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
            if (in_array($key, ['name', 'vessel', 'vessel name', 'title'], true)) {
                $map['name'] = (int) $index;
            }
            if (in_array($key, ['active', 'is_active', 'status', 'enabled'], true)) {
                $map['active'] = (int) $index;
            }
        }

        if (! isset($map['name'])) {
            fclose($handle);

            return redirect()
                ->route('settings.master-data.vessels.index')
                ->withErrors(['file' => 'The CSV must include a name column.']);
        }

        $imported = 0;
        while (($row = fgetcsv($handle)) !== false) {
            if (! is_array($row)) {
                continue;
            }

            $name = trim((string) ($row[$map['name']] ?? ''));
            if ($name === '') {
                continue;
            }

            $active = true;
            if (isset($map['active'])) {
                $v = mb_strtolower(trim((string) ($row[$map['active']] ?? '')));
                $active = $v === '' || in_array($v, ['1', 'yes', 'true', 'y', 'active'], true);
            }

            Vessel::query()->updateOrCreate(
                ['name' => $name],
                ['is_active' => $active],
            );
            $imported++;

            if ($imported > 2000) {
                break;
            }
        }

        fclose($handle);

        return redirect()
            ->route('settings.master-data.vessels.index')
            ->with('success', $imported > 0
                ? "Imported {$imported} vessel row(s)."
                : 'No rows were imported.');
    }
}
