<?php

namespace App\Http\Controllers\Settings\MasterData;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\MasterData\ImportRanksRequest;
use App\Http\Requests\Settings\MasterData\StoreRankRequest;
use App\Http\Requests\Settings\MasterData\UpdateRankRequest;
use App\Models\Rank;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

class RankController extends Controller
{
    public function index(): InertiaResponse
    {
        $ranks = Rank::query()
            ->orderBy('name')
            ->get(['id', 'name', 'is_active']);

        return Inertia::render('settings/master-data/ranks', [
            'ranks' => $ranks,
        ]);
    }

    public function store(StoreRankRequest $request): RedirectResponse
    {
        $data = $request->validated();
        $data['is_active'] = $data['is_active'] ?? true;

        Rank::query()->create($data);

        return redirect()->route('settings.master-data.ranks.index');
    }

    public function update(UpdateRankRequest $request, Rank $rank): RedirectResponse
    {
        $rank->update($request->validated());

        return redirect()->route('settings.master-data.ranks.index');
    }

    public function destroy(Rank $rank): RedirectResponse
    {
        $rank->delete();

        return redirect()->route('settings.master-data.ranks.index');
    }

    public function importTemplate(): Response
    {
        $csv = "name,is_active\nCaptain,yes\nChief Officer,yes\n";

        return response($csv, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="ranks-import-template.csv"',
        ]);
    }

    public function import(ImportRanksRequest $request): RedirectResponse
    {
        $uploaded = $request->file('file');
        $path = $uploaded->getRealPath() ?: $uploaded->path();
        $handle = fopen((string) $path, 'r');

        if ($handle === false) {
            return redirect()
                ->route('settings.master-data.ranks.index')
                ->withErrors(['file' => 'Could not read the uploaded file.']);
        }

        $header = fgetcsv($handle);
        if (! is_array($header) || count($header) === 0) {
            fclose($handle);

            return redirect()
                ->route('settings.master-data.ranks.index')
                ->withErrors(['file' => 'The CSV file is empty.']);
        }

        $map = [];
        foreach ($header as $index => $cell) {
            $key = mb_strtolower(trim((string) $cell));
            if (in_array($key, ['name', 'rank', 'rank name', 'title'], true)) {
                $map['name'] = (int) $index;
            }
            if (in_array($key, ['active', 'is_active', 'status', 'enabled'], true)) {
                $map['active'] = (int) $index;
            }
        }

        if (! isset($map['name'])) {
            fclose($handle);

            return redirect()
                ->route('settings.master-data.ranks.index')
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

            Rank::query()->updateOrCreate(
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
                ->route('settings.master-data.ranks.index')
                ->withErrors([
                    'file' => $emptyNames > 0
                        ? "No rows were imported. {$emptyNames} row(s) had an empty name."
                        : 'No rows were imported. Ensure each row has a name.',
                ]);
        }

        return redirect()
            ->route('settings.master-data.ranks.index')
            ->with('success', "Imported {$imported} rank row(s).");
    }
}
