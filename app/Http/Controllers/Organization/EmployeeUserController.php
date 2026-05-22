<?php

namespace App\Http\Controllers\Organization;

use App\Http\Controllers\Controller;
use App\Http\Requests\Organization\Employee\StoreEmployeeUserRequest;
use App\Models\Employee;
use App\Support\Users\Actions\CreateOrganizationUser;
use Illuminate\Http\RedirectResponse;

class EmployeeUserController extends Controller
{
    public function store(StoreEmployeeUserRequest $request, Employee $employee): RedirectResponse
    {
        $companyId = (int) $request->attributes->get('current_company_id');
        abort_unless((int) $employee->company_id === $companyId, 404);

        if ($employee->user_id !== null) {
            abort(422, 'This employee already has a linked user account.');
        }

        $data = $request->validated();

        $user = app(CreateOrganizationUser::class)->handle(
            $companyId,
            (string) $data['name'],
            (string) $data['email'],
            (string) $data['password'],
            (int) $data['role_id'],
        );

        $employee->update(['user_id' => $user->id]);

        return redirect()
            ->back()
            ->with('success', 'User account created and linked to employee.');
    }
}
