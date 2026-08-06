<?php

namespace App\Http\Controllers\Settings\MasterData;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Settings\MasterData\Concerns\PaginatesMasterDataIndex;
use App\Http\Requests\Settings\MasterData\StoreCurrencyRequest;
use App\Http\Requests\Settings\MasterData\UpdateCurrencyRequest;
use App\Models\Currency;
use Illuminate\Support\Str;
use Inertia\Inertia;

class CurrencyController extends Controller
{
    use PaginatesMasterDataIndex;

    public function index()
    {
        $page = $this->paginateMasterDataIndex(
            request(),
            Currency::query()
                ->orderBy('code')
                ->select(['id', 'code', 'name', 'symbol', 'is_active']),
            ['code', 'name', 'symbol'],
        );

        return Inertia::render('settings/master-data/currencies', [
            'currencies' => $page['items'],
            'pagination' => $page['pagination'],
            'search' => $page['search'],
        ]);
    }

    public function store(StoreCurrencyRequest $request)
    {
        $data = $request->validated();
        $data['code'] = Str::upper($data['code']);
        $data['is_active'] = $data['is_active'] ?? true;

        Currency::create($data);

        return redirect()->route('settings.master-data.currencies.index');
    }

    public function update(UpdateCurrencyRequest $request, Currency $currency)
    {
        $data = $request->validated();
        $data['code'] = Str::upper($data['code']);

        $currency->update($data);

        return redirect()->route('settings.master-data.currencies.index');
    }

    public function destroy(Currency $currency)
    {
        if ($currency->companies()->exists()) {
            $currency->update(['is_active' => false]);

            return redirect()->route('settings.master-data.currencies.index');
        }

        $currency->delete();

        return redirect()->route('settings.master-data.currencies.index');
    }
}
