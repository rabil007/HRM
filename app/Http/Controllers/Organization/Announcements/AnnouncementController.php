<?php

namespace App\Http\Controllers\Organization\Announcements;

use App\Enums\AnnouncementCategory;
use App\Enums\AnnouncementPriority;
use App\Enums\AnnouncementStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Organization\Announcements\StoreAnnouncementRequest;
use App\Http\Requests\Organization\Announcements\UpdateAnnouncementRequest;
use App\Models\Announcement;
use App\Models\Branch;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Position;
use App\Support\Announcements\Actions\PersistAnnouncement;
use App\Support\Announcements\Actions\PublishAnnouncement;
use App\Support\Announcements\AnnouncementPagePermissions;
use App\Support\Announcements\Resources\AnnouncementResource;
use App\Support\Pagination\ResolvesPerPage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class AnnouncementController extends Controller
{
    use ResolvesPerPage;

    public function index(Request $request): Response
    {
        $companyId = (int) $request->attributes->get('current_company_id');
        $perPage = $this->resolvePerPage($request);
        $search = trim((string) $request->query('search', ''));
        $status = trim((string) $request->query('status', ''));
        $category = trim((string) $request->query('category', ''));
        $priority = trim((string) $request->query('priority', ''));

        $paginator = Announcement::query()
            ->where('company_id', $companyId)
            ->with(['creator:id,name', 'audiences'])
            ->when($status !== '', fn ($q) => $q->where('status', $status))
            ->when($category !== '', fn ($q) => $q->where('category', $category))
            ->when($priority !== '', fn ($q) => $q->where('priority', $priority))
            ->when($search !== '', function ($q) use ($search): void {
                $q->where(function ($inner) use ($search): void {
                    $inner->where('title', 'like', "%{$search}%");
                });
            })
            ->latest('id')
            ->paginate($perPage)
            ->withQueryString();

        $announcements = $paginator->through(
            fn (Announcement $announcement) => AnnouncementResource::toListArray($announcement)
        );

        return Inertia::render('organization/announcements/index', [
            'announcements' => $announcements->items(),
            'pagination' => $this->paginationMeta($paginator),
            'search' => $search,
            'filters' => [
                'status' => $status,
                'category' => $category,
                'priority' => $priority,
            ],
            'filterOptions' => [
                'statuses' => collect(AnnouncementStatus::cases())->map(fn ($c) => [
                    'value' => $c->value,
                    'label' => $c->label(),
                ])->values()->all(),
                'categories' => collect(AnnouncementCategory::cases())->map(fn ($c) => [
                    'value' => $c->value,
                    'label' => $c->label(),
                ])->values()->all(),
                'priorities' => collect(AnnouncementPriority::cases())->map(fn ($c) => [
                    'value' => $c->value,
                    'label' => $c->label(),
                ])->values()->all(),
            ],
            'can' => AnnouncementPagePermissions::for($request->user()),
        ]);
    }

    public function create(Request $request): Response
    {
        return Inertia::render('organization/announcements/form', [
            'announcement' => null,
            'options' => $this->formOptions((int) $request->attributes->get('current_company_id')),
            'can' => AnnouncementPagePermissions::for($request->user()),
        ]);
    }

    public function store(
        StoreAnnouncementRequest $request,
        PersistAnnouncement $persist,
        PublishAnnouncement $publish,
    ): RedirectResponse {
        $companyId = (int) $request->attributes->get('current_company_id');
        $data = $request->validated();
        $user = $request->user();
        abort_unless($user !== null, 403);

        $announcement = $persist->create($companyId, $user, $data);

        if (($data['publish_mode'] ?? '') === 'send_now') {
            $announcement = $publish->handle($announcement, $user);

            return redirect()
                ->route('organization.announcements.show', $announcement)
                ->with('success', 'Announcement published.');
        }

        $message = ($data['publish_mode'] ?? '') === 'schedule'
            ? 'Announcement scheduled.'
            : 'Announcement saved as draft.';

        return redirect()
            ->route('organization.announcements.show', $announcement)
            ->with('success', $message);
    }

    public function show(Request $request, Announcement $announcement): Response
    {
        $this->assertCompany($request, $announcement);

        return Inertia::render('organization/announcements/show', [
            'announcement' => AnnouncementResource::toShowArray($announcement),
            'can' => AnnouncementPagePermissions::for($request->user()),
        ]);
    }

    public function edit(Request $request, Announcement $announcement): Response
    {
        $this->assertCompany($request, $announcement);
        abort_unless($announcement->status->isEditable(), 403);

        return Inertia::render('organization/announcements/form', [
            'announcement' => AnnouncementResource::toFormArray($announcement),
            'options' => $this->formOptions((int) $request->attributes->get('current_company_id')),
            'can' => AnnouncementPagePermissions::for($request->user()),
        ]);
    }

    public function update(
        UpdateAnnouncementRequest $request,
        Announcement $announcement,
        PersistAnnouncement $persist,
        PublishAnnouncement $publish,
    ): RedirectResponse {
        $this->assertCompany($request, $announcement);
        $data = $request->validated();
        $user = $request->user();
        abort_unless($user !== null, 403);

        $announcement = $persist->update($announcement, $data);

        if (($data['publish_mode'] ?? '') === 'send_now') {
            $announcement = $publish->handle($announcement, $user);

            return redirect()
                ->route('organization.announcements.show', $announcement)
                ->with('success', 'Announcement published.');
        }

        return redirect()
            ->route('organization.announcements.show', $announcement)
            ->with('success', 'Announcement updated.');
    }

    public function destroy(Request $request, Announcement $announcement): RedirectResponse
    {
        $this->assertCompany($request, $announcement);

        if (! $announcement->status->isDeletable()) {
            throw ValidationException::withMessages([
                'status' => 'Only draft announcements can be deleted.',
            ]);
        }

        $announcement->delete();

        return redirect()
            ->route('organization.announcements.index')
            ->with('success', 'Draft announcement deleted.');
    }

    private function assertCompany(Request $request, Announcement $announcement): void
    {
        $companyId = (int) $request->attributes->get('current_company_id');
        abort_unless((int) $announcement->company_id === $companyId, 404);
    }

    /**
     * @return array<string, mixed>
     */
    private function formOptions(int $companyId): array
    {
        return [
            'categories' => collect(AnnouncementCategory::cases())->map(fn ($c) => [
                'value' => $c->value,
                'label' => $c->label(),
            ])->values()->all(),
            'priorities' => collect(AnnouncementPriority::cases())->map(fn ($c) => [
                'value' => $c->value,
                'label' => $c->label(),
            ])->values()->all(),
            'branches' => Branch::query()
                ->where('company_id', $companyId)
                ->where('status', 'active')
                ->orderBy('name')
                ->get(['id', 'name']),
            'departments' => Department::query()
                ->where('company_id', $companyId)
                ->where('status', 'active')
                ->orderBy('name')
                ->get(['id', 'name']),
            'positions' => Position::query()
                ->where('company_id', $companyId)
                ->where('status', 'active')
                ->orderBy('title')
                ->get(['id', 'title'])
                ->map(fn (Position $position): array => [
                    'id' => $position->id,
                    'name' => $position->title,
                ])
                ->values()
                ->all(),
            'employees' => Employee::query()
                ->where('company_id', $companyId)
                ->where('status', 'active')
                ->orderBy('name')
                ->limit(500)
                ->get(['id', 'name', 'employee_no']),
        ];
    }
}
