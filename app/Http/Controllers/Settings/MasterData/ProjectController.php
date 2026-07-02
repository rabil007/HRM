<?php

namespace App\Http\Controllers\Settings\MasterData;

use App\Http\Controllers\Concerns\ReturnsQuickCreateJson;
use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\MasterData\ImportProjectsRequest;
use App\Http\Requests\Settings\MasterData\StoreProjectRequest;
use App\Http\Requests\Settings\MasterData\UpdateProjectRequest;
use App\Models\Project;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;
use Inertia\Inertia;

class ProjectController extends Controller
{
    use ReturnsQuickCreateJson;

    public function index()
    {
        $projects = Project::query()
            ->orderBy('title')
            ->get(['id', 'title', 'is_active']);

        return Inertia::render('settings/master-data/projects', [
            'projects' => $projects,
        ]);
    }

    public function store(StoreProjectRequest $request): JsonResponse|RedirectResponse
    {
        $data = $request->validated();
        $data['is_active'] = $data['is_active'] ?? true;

        return $this->createOrReturnExistingQuickCreate(
            $request,
            Project::class,
            $data,
            redirect()->route('settings.master-data.projects.index'),
            'title',
        );
    }

    public function update(UpdateProjectRequest $request, Project $project)
    {
        $project->update($request->validated());

        return redirect()->route('settings.master-data.projects.index');
    }

    public function destroy(Project $project)
    {
        $project->delete();

        return redirect()->route('settings.master-data.projects.index');
    }

    public function importTemplate(): Response
    {
        $csv = "title,is_active\nNorth Field,yes\nSouth Field,yes\n";

        return response($csv, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="projects-import-template.csv"',
        ]);
    }

    public function import(ImportProjectsRequest $request)
    {
        $uploaded = $request->file('file');
        $path = $uploaded->getRealPath() ?: $uploaded->path();
        $handle = fopen((string) $path, 'r');

        if ($handle === false) {
            return redirect()
                ->route('settings.master-data.projects.index')
                ->withErrors(['file' => 'Could not read the uploaded file.']);
        }

        $header = fgetcsv($handle);
        if (! is_array($header) || count($header) === 0) {
            fclose($handle);

            return redirect()
                ->route('settings.master-data.projects.index')
                ->withErrors(['file' => 'The CSV file is empty.']);
        }

        $map = [];
        foreach ($header as $index => $cell) {
            $key = mb_strtolower(trim((string) $cell));
            if (in_array($key, ['title', 'name', 'project'], true)) {
                $map['title'] = (int) $index;
            }
            if (in_array($key, ['active', 'is_active', 'status', 'enabled'], true)) {
                $map['active'] = (int) $index;
            }
        }

        if (! isset($map['title'])) {
            fclose($handle);

            return redirect()
                ->route('settings.master-data.projects.index')
                ->withErrors(['file' => 'The CSV must include a title column.']);
        }

        $imported = 0;
        $emptyTitles = 0;

        while (($row = fgetcsv($handle)) !== false) {
            if (! is_array($row)) {
                continue;
            }

            $title = trim((string) ($row[$map['title']] ?? ''));
            if ($title === '') {
                $emptyTitles++;

                continue;
            }

            $active = true;
            if (isset($map['active'])) {
                $v = mb_strtolower(trim((string) ($row[$map['active']] ?? '')));
                $active = $v === '' || in_array($v, ['1', 'yes', 'true', 'y', 'active'], true);
            }

            Project::query()->updateOrCreate(
                ['title' => $title],
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
                ->route('settings.master-data.projects.index')
                ->withErrors([
                    'file' => $emptyTitles > 0
                        ? "No rows were imported. {$emptyTitles} row(s) had an empty title."
                        : 'No rows were imported. Ensure each row has a title.',
                ]);
        }

        return redirect()
            ->route('settings.master-data.projects.index')
            ->with('success', "Imported {$imported} project row(s).");
    }
}
