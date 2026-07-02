<?php

namespace App\Http\Controllers\Settings\MasterData;

use App\Http\Controllers\Concerns\ReturnsQuickCreateJson;
use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\MasterData\StoreProjectRequest;
use App\Http\Requests\Settings\MasterData\UpdateProjectRequest;
use App\Models\Project;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
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
}
