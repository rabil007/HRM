<?php

namespace App\Http\Controllers\Settings\MasterData;

use App\Http\Controllers\Concerns\ReturnsQuickCreateJson;
use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\MasterData\StoreApprovalLocationRequest;
use App\Http\Requests\Settings\MasterData\UpdateApprovalLocationRequest;
use App\Models\ApprovalLocation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

class ApprovalLocationController extends Controller
{
    use ReturnsQuickCreateJson;

    public function index()
    {
        $approvalLocations = ApprovalLocation::query()
            ->orderBy('name')
            ->get(['id', 'name', 'is_active']);

        return Inertia::render('settings/master-data/approval-locations', [
            'approval_locations' => $approvalLocations,
        ]);
    }

    public function store(StoreApprovalLocationRequest $request): JsonResponse|RedirectResponse
    {
        $data = $request->validated();
        $data['is_active'] = $data['is_active'] ?? true;

        return $this->createOrReturnExistingQuickCreate(
            $request,
            ApprovalLocation::class,
            $data,
            redirect()->route('settings.master-data.approval-locations.index'),
        );
    }

    public function update(UpdateApprovalLocationRequest $request, ApprovalLocation $approvalLocation)
    {
        $approvalLocation->update($request->validated());

        return redirect()->route('settings.master-data.approval-locations.index');
    }

    public function destroy(ApprovalLocation $approvalLocation)
    {
        $approvalLocation->delete();

        return redirect()->route('settings.master-data.approval-locations.index');
    }
}
