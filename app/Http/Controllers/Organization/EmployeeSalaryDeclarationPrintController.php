<?php

namespace App\Http\Controllers\Organization;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Support\Employees\Services\SalaryDeclarationData;
use Illuminate\Http\Request;

class EmployeeSalaryDeclarationPrintController extends Controller
{
    public function __invoke(Request $request, Employee $employee)
    {
        $companyId = (int) $request->attributes->get('current_company_id');
        $data = SalaryDeclarationData::for($employee, $companyId);
        $data['printable'] = true;

        return view('employees.salary-declaration', $data);
    }
}
