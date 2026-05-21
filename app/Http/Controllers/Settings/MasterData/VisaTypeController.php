<?php

namespace App\Http\Controllers\Settings\MasterData;

use App\Http\Controllers\Concerns\ReturnsQuickCreateJson;
use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\MasterData\StoreVisaTypeRequest;
use App\Http\Requests\Settings\MasterData\UpdateVisaTypeRequest;
use App\Models\VisaType;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

class VisaTypeController extends Controller
{
    use ReturnsQuickCreateJson;

    public function index()
    {
        $visaTypes = VisaType::query()
            ->orderBy('name')
            ->get(['id', 'name', 'is_active']);

        return Inertia::render('settings/master-data/visa-types', [
            'visa_types' => $visaTypes,
        ]);
    }

    public function store(StoreVisaTypeRequest $request): JsonResponse|RedirectResponse
    {
        $data = $request->validated();
        $data['is_active'] = $data['is_active'] ?? true;

        return $this->createOrReturnExistingQuickCreate(
            $request,
            VisaType::class,
            $data,
            redirect()->route('settings.master-data.visa-types.index'),
        );
    }

    public function update(UpdateVisaTypeRequest $request, VisaType $visaType)
    {
        $data = $request->validated();

        $visaType->update($data);

        return redirect()->route('settings.master-data.visa-types.index');
    }

    public function destroy(VisaType $visaType)
    {
        $visaType->delete();

        return redirect()->route('settings.master-data.visa-types.index');
    }
}
