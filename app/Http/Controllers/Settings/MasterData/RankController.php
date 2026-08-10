<?php

namespace App\Http\Controllers\Settings\MasterData;

use App\Http\Controllers\Concerns\ReturnsQuickCreateJson;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Settings\MasterData\Concerns\PaginatesMasterDataIndex;
use App\Http\Requests\Settings\MasterData\ImportRanksRequest;
use App\Http\Requests\Settings\MasterData\StoreRankRequest;
use App\Http\Requests\Settings\MasterData\UpdateRankRequest;
use App\Models\Rank;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

class RankController extends Controller
{
    use PaginatesMasterDataIndex;
    use ReturnsQuickCreateJson;

    public function index(): InertiaResponse
    {
        $page = $this->paginateMasterDataIndex(
            request(),
            Rank::query()
                ->orderBy('name')
                ->select(['id', 'name', 'is_active', 'max_tour_of_duty_days']),
            ['name'],
        );

        return Inertia::render('settings/master-data/ranks', [
            'ranks' => $page['items'],
            'pagination' => $page['pagination'],
            'search' => $page['search'],
        ]);
    }

    public function store(StoreRankRequest $request): JsonResponse|RedirectResponse
    {
        $data = $request->validated();
        $data['is_active'] = $data['is_active'] ?? true;

        return $this->createOrReturnExistingQuickCreate(
            $request,
            Rank::class,
            $data,
            redirect()->route('settings.master-data.ranks.index'),
        );
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
        $csv = "name,is_active,max_tour_of_duty_days\nCaptain,yes,90\nChief Officer,yes,90\nSecond Engineer,yes,75\n";

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
            if (in_array($key, [
                'max_tour_of_duty_days',
                'tour_of_duty_days',
                'tour_of_duty',
                'tod',
                'tod_days',
            ], true)) {
                $map['tour_of_duty'] = (int) $index;
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
        $invalidTourRows = 0;

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

            $attributes = ['is_active' => $active];

            if (isset($map['tour_of_duty'])) {
                $rawTour = trim((string) ($row[$map['tour_of_duty']] ?? ''));

                if ($rawTour === '') {
                    // Blank preserves the existing Tour of Duty value on update.
                } elseif (! ctype_digit($rawTour) && ! preg_match('/^-?\d+$/', $rawTour)) {
                    $invalidTourRows++;

                    continue;
                } else {
                    $tourDays = (int) $rawTour;
                    if ($tourDays < 1 || $tourDays > 365) {
                        $invalidTourRows++;

                        continue;
                    }
                    $attributes['max_tour_of_duty_days'] = $tourDays;
                }
            }

            Rank::query()->updateOrCreate(
                ['name' => $name],
                $attributes,
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
                    'file' => $invalidTourRows > 0
                        ? "No rows were imported. {$invalidTourRows} row(s) had an invalid Tour of Duty (must be 1–365 or blank)."
                        : ($emptyNames > 0
                            ? "No rows were imported. {$emptyNames} row(s) had an empty name."
                            : 'No rows were imported. Ensure each row has a name.'),
                ]);
        }

        $message = "Imported {$imported} rank row(s).";
        if ($invalidTourRows > 0) {
            $message .= " Skipped {$invalidTourRows} row(s) with invalid Tour of Duty values.";
        }

        return redirect()
            ->route('settings.master-data.ranks.index')
            ->with('success', $message);
    }
}
