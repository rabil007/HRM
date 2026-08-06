<?php

namespace App\Http\Controllers\Settings\MasterData;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Settings\MasterData\Concerns\PaginatesMasterDataIndex;
use App\Http\Requests\Settings\MasterData\StoreCountryRequest;
use App\Http\Requests\Settings\MasterData\UpdateCountryRequest;
use App\Models\Country;
use Illuminate\Support\Str;
use Inertia\Inertia;

class CountryController extends Controller
{
    use PaginatesMasterDataIndex;

    public function index()
    {
        $page = $this->paginateMasterDataIndex(
            request(),
            Country::query()
                ->orderBy('name')
                ->select(['id', 'code', 'name', 'dial_code', 'is_active']),
            ['code', 'name', 'dial_code'],
        );

        return Inertia::render('settings/master-data/countries', [
            'countries' => $page['items'],
            'pagination' => $page['pagination'],
            'search' => $page['search'],
        ]);
    }

    public function store(StoreCountryRequest $request)
    {
        $data = $request->validated();
        $data['code'] = Str::upper($data['code']);
        $data['is_active'] = $data['is_active'] ?? true;

        Country::create($data);

        return redirect()->route('settings.master-data.countries.index');
    }

    public function update(UpdateCountryRequest $request, Country $country)
    {
        $data = $request->validated();
        $data['code'] = Str::upper($data['code']);

        $country->update($data);

        return redirect()->route('settings.master-data.countries.index');
    }

    public function destroy(Country $country)
    {
        if ($country->companies()->exists()) {
            $country->update(['is_active' => false]);

            return redirect()->route('settings.master-data.countries.index');
        }

        $country->delete();

        return redirect()->route('settings.master-data.countries.index');
    }
}
