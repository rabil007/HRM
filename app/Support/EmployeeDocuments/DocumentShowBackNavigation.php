<?php

namespace App\Support\EmployeeDocuments;

use App\Models\Employee;
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
        $query = [];

        $expiry = (string) $request->query('expiry', 'all');
        if (DocumentExpiry::isValidFilter($expiry) && $expiry !== 'all') {
            $query['expiry'] = $expiry;
        }

        $search = trim((string) $request->query('search', ''));
        if ($search !== '') {
            $query['search'] = $search;
        }

        $page = (int) $request->query('page', 0);
        if ($page > 1) {
            $query['page'] = (string) $page;
        }

        return $query;
    }
}
