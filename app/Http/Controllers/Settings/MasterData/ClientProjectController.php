<?php

namespace App\Http\Controllers\Settings\MasterData;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\Project;
use App\Support\MasterData\SyncProjectClients;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class ClientProjectController extends Controller
{
    public function store(Request $request, Client $client, SyncProjectClients $syncProjectClients): RedirectResponse
    {
        abort_unless($request->user()?->can('settings.master-data.projects.create'), 403);

        if (! $client->is_active) {
            throw ValidationException::withMessages([
                'title' => 'Activate this client before adding new projects or vessels.',
            ]);
        }

        $validated = $request->validate([
            'title' => ['required', 'string', 'max:200'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $title = trim((string) $validated['title']);

        $existing = Project::query()
            ->where('title', $title)
            ->whereNull('deleted_at')
            ->first();

        if ($existing instanceof Project) {
            throw ValidationException::withMessages([
                'title' => 'A project with this title already exists. Use "Attach Existing" to assign it to this client.',
            ]);
        }

        $project = $syncProjectClients->create(
            [
                'title' => $title,
                'is_active' => $request->boolean('is_active', true),
            ],
            [(int) $client->id],
        );

        return redirect()
            ->route('settings.master-data.clients.show', $client)
            ->with('success', "Project '{$project->title}' created and attached successfully.");
    }

    public function attach(Request $request, Client $client, SyncProjectClients $syncProjectClients, ?Project $project = null): RedirectResponse
    {
        abort_unless($request->user()?->can('settings.master-data.projects.update'), 403);

        if (! $client->is_active) {
            throw ValidationException::withMessages([
                'project_id' => 'Activate this client before adding new projects or vessels.',
            ]);
        }

        if (! $project || ! $project->exists) {
            $validated = $request->validate([
                'project_id' => ['required', 'integer', 'exists:projects,id'],
            ]);

            $project = Project::query()
                ->whereNull('deleted_at')
                ->findOrFail($validated['project_id']);
        }

        $currentClientIds = $syncProjectClients->currentClientIds($project);

        if (in_array((int) $client->id, $currentClientIds, true)) {
            return redirect()
                ->route('settings.master-data.clients.show', $client)
                ->with('status', "Project '{$project->title}' is already assigned to this client.")
                ->with('info', "Project '{$project->title}' is already assigned to this client.");
        }

        if (! $project->is_active) {
            throw ValidationException::withMessages([
                'project_id' => 'Cannot attach an inactive project. Please activate the project first.',
            ]);
        }

        $syncProjectClients->attach($project, [(int) $client->id]);

        return redirect()
            ->route('settings.master-data.clients.show', $client)
            ->with('success', "Project '{$project->title}' attached successfully.");
    }
}
