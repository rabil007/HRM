<?php

namespace App\Support\EmployeeDocuments;

use App\Models\Employee;
use App\Support\Documents\DocumentsLibraryQueryState;
use Illuminate\Http\Request;

class DocumentShowBackNavigation
{
    /**
     * @return array{href: string, label: string}
     */
    public static function resolve(Request $request, Employee $employee): array
    {
        $from = (string) $request->query('from', 'employee-browse');

        return match ($from) {
            'profile' => [
                'href' => route('organization.employees.show', $employee).'#documents',
                'label' => 'Back to employee profile',
            ],
            'index' => [
                'href' => route('organization.documents', self::indexQuery($request)),
                'label' => 'Back to documents',
            ],
            'library' => [
                'href' => route('organization.documents.library', self::indexQuery($request)),
                'label' => 'Back to Library',
            ],
            default => [
                'href' => route('organization.documents.employee', $employee),
                'label' => 'Back to files',
            ],
        };
    }

    /**
     * @return array<string, string>
     */
    private static function indexQuery(Request $request): array
    {
        return DocumentsLibraryQueryState::fromRequest($request)->toQuery();
    }
}
