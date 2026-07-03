<?php

use App\Support\Employees\EmployeeImportTemplateExporter;
use Illuminate\Testing\TestResponse;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * @return list<string>
 */
function employeeImportTemplateHeaders(TestResponse $response): array
{
    $sheet = employeeImportTemplateSpreadsheet($response)
        ->getSheetByName(EmployeeImportTemplateExporter::IMPORT_SHEET_NAME);

    $headers = [];
    $column = 1;

    while (true) {
        $value = $sheet->getCellByColumnAndRow($column, 1)->getValue();

        if ($value === null || $value === '') {
            break;
        }

        $headers[] = (string) $value;
        $column++;
    }

    return $headers;
}

function employeeImportTemplateSpreadsheet(TestResponse $response): Spreadsheet
{
    $baseResponse = $response->baseResponse;

    if ($baseResponse instanceof BinaryFileResponse) {
        $path = $baseResponse->getFile()->getPathname();
    } else {
        $path = tempnam(sys_get_temp_dir(), 'employee-import-template-test-').'.xlsx';
        file_put_contents($path, $response->getContent());
    }

    return IOFactory::load($path);
}
