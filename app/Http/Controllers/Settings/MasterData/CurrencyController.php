<?php

namespace App\Http\Controllers\Settings\MasterData;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\MasterData\StoreCurrencyRequest;
use App\Http\Requests\Settings\MasterData\UpdateCurrencyRequest;
use App\Models\Currency;
use Illuminate\Support\Str;
use Inertia\Inertia;

class CurrencyController extends Controller
{
    public function index()
    {
        $currencies = Currency::query()
            ->orderBy('code')
            ->get(['id', 'code', 'name', 'symbol', 'precision', 'is_active']);

        return Inertia::render('settings/master-data/currencies', [
            'currencies' => $currencies,
        ]);
    }

    public function store(StoreCurrencyRequest $request)
    {
        $data = $request->validated();
        $data['code'] = Str::upper($data['code']);
        $data['is_active'] = $data['is_active'] ?? true;
        $data['precision'] = $data['precision'] ?? 2;

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
