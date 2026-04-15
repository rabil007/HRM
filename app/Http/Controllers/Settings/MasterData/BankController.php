<?php

namespace App\Http\Controllers\Settings\MasterData;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\MasterData\StoreBankRequest;
use App\Http\Requests\Settings\MasterData\UpdateBankRequest;
use App\Models\Bank;
use App\Models\Country;
use Inertia\Inertia;

class BankController extends Controller
{
    public function index()
    {
        $countries = Country::query()
            ->orderBy('name')
            ->get(['id', 'code', 'name']);

        $banks = Bank::query()
            ->with(['country:id,name,code'])
            ->orderBy('name')
            ->get([
                'id',
                'name',
                'uae_routing_code_agent_id',
                'country_id',
                'is_active',
            ]);

        return Inertia::render('settings/master-data/banks', [
            'banks' => $banks,
            'countries' => $countries,
        ]);
    }

    public function store(StoreBankRequest $request)
    {
        $data = $request->validated();
        $data['is_active'] = $data['is_active'] ?? true;

        Bank::create($data);

        return redirect()->route('settings.master-data.banks.index');
    }

    public function update(UpdateBankRequest $request, Bank $bank)
    {
        $data = $request->validated();

        $bank->update($data);

        return redirect()->route('settings.master-data.banks.index');
    }

    public function destroy(Bank $bank)
    {
        $bank->delete();

        return redirect()->route('settings.master-data.banks.index');
    }
}
