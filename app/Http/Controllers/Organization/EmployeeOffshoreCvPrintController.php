<?php

namespace App\Http\Controllers\Organization;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Support\Employees\Services\OffshoreCvData;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class EmployeeOffshoreCvPrintController extends Controller
{
    public function __invoke(Request $request, Employee $employee)
    {
        $companyId = (int) $request->attributes->get('current_company_id');
        $data = OffshoreCvData::for($employee, $companyId);
        $isPdf = $request->query('format') === 'pdf';
        $data['printable'] = ! $isPdf;
        $data['is_pdf'] = $isPdf;

        if ($isPdf) {
            $filename = 'offshore-cv-'.Str::slug($employee->employee_no ?: 'employee').'-'.now()->format('Y-m-d').'.pdf';

            $pdf = Pdf::loadView('employees.offshore-cv', $data)
                ->setPaper('a4', 'portrait')
                ->setOption('isRemoteEnabled', true)
                ->setOption('isHtml5ParserEnabled', true)
                ->setOption('defaultFont', 'DejaVu Sans')
                ->setOption('dpi', 72)
                ->setOption('defaultMediaType', 'print')
                ->setOption('isFontSubsettingEnabled', true);

            if ($request->boolean('inline', true)) {
                return $pdf->stream($filename);
            }

            return $pdf->download($filename);
        }

        return view('employees.offshore-cv', $data);
    }
}
