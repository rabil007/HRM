<?php

namespace App\Services\SalaryDeclaration;

use App\Models\Employee;
use App\Support\BulkDocuments\ConfiguresBrowsershotEnvironment;
use App\Support\BulkDocuments\ResolvesBrowsershotBinaries;
use App\Support\Employees\Services\SalaryDeclarationData;
use Spatie\Browsershot\Browsershot;

final class SalaryDeclarationPdfRenderer implements RendersSalaryDeclarationPdf
{
    public function render(Employee $employee, int $companyId): string
    {
        ConfiguresBrowsershotEnvironment::apply();

        $data = SalaryDeclarationData::for($employee, $companyId);
        $data['printable'] = false;

        $html = view('employees.salary-declaration', $data)->render();

        $binaries = ResolvesBrowsershotBinaries::resolve();

        $shot = Browsershot::html($html)
            ->showBackground()
            ->format('A4')
            ->margins(14, 14, 14, 14)
            ->emulateMedia('print')
            ->setNodeModulePath(base_path('node_modules'))
            ->setNodeBinary($binaries['node'])
            ->setNpmBinary($binaries['npm'])
            ->noSandbox()
            ->addChromiumArguments([
                'disable-dev-shm-usage',
                'disable-gpu',
            ]);

        if ($binaries['chrome'] !== null) {
            $shot->setChromePath($binaries['chrome']);
        }

        return $shot->pdf();
    }
}
