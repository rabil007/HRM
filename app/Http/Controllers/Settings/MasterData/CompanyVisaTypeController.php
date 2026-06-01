<?php

namespace App\Http\Controllers\Settings\MasterData;

use App\Http\Controllers\Concerns\ReturnsQuickCreateJson;
use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\MasterData\StoreCompanyVisaTypeRequest;
use App\Http\Requests\Settings\MasterData\UpdateCompanyVisaTypeRequest;
use App\Models\CompanyVisaType;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

class CompanyVisaTypeController extends Controller
{
    use ReturnsQuickCreateJson;

    public function index()
    {
        $companyVisaTypes = CompanyVisaType::query()
            ->orderBy('name')
            ->get(['id', 'name', 'is_active']);

        return Inertia::render('settings/master-data/company-visa-types', [
            'company_visa_types' => $companyVisaTypes,
        ]);
    }

    public function store(StoreCompanyVisaTypeRequest $request): JsonResponse|RedirectResponse
    {
        $data = $request->validated();
        $data['is_active'] = $data['is_active'] ?? true;

        return $this->createOrReturnExistingQuickCreate(
            $request,
            CompanyVisaType::class,
            $data,
            redirect()->route('settings.master-data.company-visa-types.index'),
        );
    }

    public function update(UpdateCompanyVisaTypeRequest $request, CompanyVisaType $companyVisaType)
    {
        $data = $request->validated();

        $companyVisaType->update($data);

        return redirect()->route('settings.master-data.company-visa-types.index');
    }

    public function destroy(CompanyVisaType $companyVisaType)
    {
        $companyVisaType->delete();

        return redirect()->route('settings.master-data.company-visa-types.index');
    }
}
