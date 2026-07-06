<?php

namespace App\Services\SalaryDeclaration;

use App\Models\Employee;
use App\Support\Employees\Services\SalaryDeclarationData;
use Spatie\Browsershot\Browsershot;

final class SalaryDeclarationPdfRenderer implements RendersSalaryDeclarationPdf
{
    public function render(Employee $employee, int $companyId): string
    {
        $data = SalaryDeclarationData::for($employee, $companyId);
        $data['printable'] = false;

        $html = view('employees.salary-declaration', $data)->render();

        $shot = Browsershot::html($html)
            ->showBackground()
            ->format('A4')
            ->margins(14, 14, 14, 14)
            ->emulateMedia('print')
            ->setNodeModulePath(base_path('node_modules'))
            ->noSandbox();

        $nodeBinary = config('services.browsershot.node_binary');

        if (is_string($nodeBinary) && $nodeBinary !== '') {
            $shot->setNodeBinary($nodeBinary);
        }

        $npmBinary = config('services.browsershot.npm_binary');

        if (is_string($npmBinary) && $npmBinary !== '') {
            $shot->setNpmBinary($npmBinary);
        }

        return $shot->pdf();
    }
}
