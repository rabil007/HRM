<?php

namespace App\Http\Controllers;

use App\Support\Platform\PlatformAudit;
use App\Support\Platform\PlatformDatabaseCatalog;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DatabaseViewerController extends Controller
{
    public function index(Request $request): Response
    {
        $search = $request->input('search');

        $tables = collect(PlatformDatabaseCatalog::listBrowsableTables());

        if (filled($search)) {
            $needle = strtolower((string) $search);
            $tables = $tables->filter(function (string $table) use ($needle): bool {
                return str_contains($table, $needle);
            });
        }

        return Inertia::render('mysql/index', [
            'tables' => $tables->values()->all(),
            'filters' => $request->only('search'),
        ]);
    }

    public function show(Request $request, string $table): Response
    {
        $columns = $this->visibleColumnsFor($table);
        $search = $request->input('search');
        $sortBy = $request->input('sort_by');
        $sortDir = $request->input('sort_dir', 'asc');
        $columnFilters = $request->input('column_filters', []);

        $query = DB::table($table)->select($columns);
        $query = $this->applyFilters($query, $columns, $search, $columnFilters);

        if (is_string($sortBy) && in_array($sortBy, $columns, true)) {
            $query->orderBy($sortBy, $sortDir === 'desc' ? 'desc' : 'asc');
        }

        $data = $query->paginate(50)->withQueryString();

        PlatformAudit::record($request->user(), 'Browsed platform database table', [
            'action' => 'platform.database.browse',
            'table' => $table,
            'has_search' => filled($search),
        ]);

        return Inertia::render('mysql/show', [
            'tableName' => $table,
            'columns' => $columns,
            'data' => $data,
            'filters' => $request->only('search', 'sort_by', 'sort_dir', 'column_filters'),
        ]);
    }

    public function export(Request $request, string $table): StreamedResponse
    {
        $columns = $this->visibleColumnsFor($table);
        $search = $request->input('search');
        $sortBy = $request->input('sort_by');
        $sortDir = $request->input('sort_dir', 'asc');
        $columnFilters = $request->input('column_filters', []);

        $query = DB::table($table)->select($columns);
        $query = $this->applyFilters($query, $columns, $search, $columnFilters);

        if (is_string($sortBy) && in_array($sortBy, $columns, true)) {
            $query->orderBy($sortBy, $sortDir === 'desc' ? 'desc' : 'asc');
        }

        PlatformAudit::record($request->user(), 'Exported platform database table', [
            'action' => 'platform.database.export',
            'table' => $table,
            'has_search' => filled($search),
        ]);

        $headers = [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => "attachment; filename=\"{$table}_export.csv\"",
            'Pragma' => 'no-cache',
            'Cache-Control' => 'must-revalidate, post-check=0, pre-check=0',
            'Expires' => '0',
        ];

        $callback = function () use ($query, $columns): void {
            $file = fopen('php://output', 'w');
            fwrite($file, chr(0xEF).chr(0xBB).chr(0xBF));
            fputcsv($file, $columns);

            foreach ($query->cursor() as $record) {
                $row = [];
                foreach ($columns as $col) {
                    $val = $record->{$col};
                    $row[] = is_scalar($val) || is_null($val) ? $val : json_encode($val);
                }
                fputcsv($file, $row);
            }

            fclose($file);
        };

        return response()->streamDownload($callback, "{$table}_export.csv", $headers);
    }

    /**
     * @return list<string>
     */
    private function visibleColumnsFor(string $table): array
    {
        PlatformDatabaseCatalog::assertBrowsable($table);

        $columns = PlatformDatabaseCatalog::visibleColumns($table);

        if ($columns === []) {
            abort(404);
        }

        return $columns;
    }

    /**
     * @param  Builder  $query
     * @param  list<string>  $columns
     * @return Builder
     */
    private function applyFilters($query, array $columns, mixed $search, mixed $columnFilters)
    {
        if (filled($search)) {
            $query->where(function ($q) use ($columns, $search): void {
                foreach ($columns as $column) {
                    $q->orWhere($column, 'LIKE', '%'.$search.'%');
                }
            });
        }

        if (is_array($columnFilters)) {
            foreach ($columnFilters as $column => $value) {
                if ($value !== null && $value !== '' && in_array($column, $columns, true)) {
                    $query->where($column, 'LIKE', '%'.$value.'%');
                }
            }
        }

        return $query;
    }
}
