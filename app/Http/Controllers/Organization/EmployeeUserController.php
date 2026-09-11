<?php

namespace App\Http\Controllers\Organization;

use App\Http\Controllers\Controller;
use App\Http\Requests\Organization\Employee\StoreEmployeeUserRequest;
use App\Models\Employee;
use App\Support\Users\InviteUser;
use Illuminate\Http\RedirectResponse;

class EmployeeUserController extends Controller
{
    public function store(
        StoreEmployeeUserRequest $request,
        Employee $employee,
        InviteUser $inviteUser,
    ): RedirectResponse {
        $companyId = (int) $request->attributes->get('current_company_id');
        abort_unless((int) $employee->company_id === $companyId, 404);

        if ($employee->user_id !== null) {
            abort(422, 'This employee already has a linked user account.');
        }

        $data = $request->validated();

        $inviteUser->execute([
            'email' => $data['email'],
            'name' => $data['name'],
            'role_id' => (int) $data['role_id'],
            'employee_id' => $employee->id,
        ], $companyId, (int) $request->user()->id);

        return redirect()
            ->back()
            ->with('success', 'Invitation sent successfully.');
    }
}
