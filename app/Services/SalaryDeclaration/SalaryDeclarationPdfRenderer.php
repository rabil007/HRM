<?php

namespace App\Services\SalaryDeclaration;

use App\Models\Employee;
use App\Support\BulkDocuments\ConfiguresBrowsershotEnvironment;
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

        $nodeBinary = config('services.browsershot.node_binary');
        $npmBinary = config('services.browsershot.npm_binary');
        $chromePath = config('services.browsershot.chrome_path');

        $shot = Browsershot::html($html)
            ->showBackground()
            ->format('A4')
            ->margins(14, 14, 14, 14)
            ->emulateMedia('print')
            ->setNodeModulePath(base_path('node_modules'))
            ->noSandbox()
            ->addChromiumArguments([
                'disable-dev-shm-usage',
                'disable-gpu',
            ]);

        if (is_string($nodeBinary) && $nodeBinary !== '') {
            $shot->setNodeBinary($nodeBinary);
        }

        if (is_string($npmBinary) && $npmBinary !== '') {
            $shot->setNpmBinary($npmBinary);
        }

        if (is_string($chromePath) && $chromePath !== '') {
            $shot->setChromePath($chromePath);
        }

        return $shot->pdf();
    }
}
