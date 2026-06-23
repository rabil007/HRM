<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Inertia\Inertia;

class DatabaseViewerController extends Controller
{
    public function index(Request $request)
    {
        $search = $request->input('search');

        $tables = collect(DB::select('SHOW TABLES'))->map(function ($table) {
            return array_values((array) $table)[0];
        });

        if ($search) {
            $tables = $tables->filter(function ($table) use ($search) {
                return str_contains(strtolower($table), strtolower($search));
            });
        }

        return Inertia::render('mysql/index', [
            'tables' => $tables->values(),
            'filters' => $request->only('search')
        ]);
    }

    private function applyFilters($query, $columns, $search, $columnFilters)
    {
        if ($search) {
            $query->where(function ($q) use ($columns, $search) {
                foreach ($columns as $column) {
                    $q->orWhere($column, 'LIKE', "%{$search}%");
                }
            });
        }

        if (is_array($columnFilters)) {
            foreach ($columnFilters as $column => $value) {
                if ($value !== null && $value !== '' && in_array($column, $columns)) {
                    $query->where($column, 'LIKE', "%{$value}%");
                }
            }
        }

        return $query;
    }

    public function show(Request $request, $table)
    {
        if (!Schema::hasTable($table)) {
            abort(404);
        }

        $columns = Schema::getColumnListing($table);
        $search = $request->input('search');
        $sortBy = $request->input('sort_by');
        $sortDir = $request->input('sort_dir', 'asc');
        $columnFilters = $request->input('column_filters', []);

        $query = DB::table($table);
        $query = $this->applyFilters($query, $columns, $search, $columnFilters);

        if ($sortBy && in_array($sortBy, $columns)) {
            $query->orderBy($sortBy, $sortDir === 'desc' ? 'desc' : 'asc');
        }

        $data = $query->paginate(50)->withQueryString();

        return Inertia::render('mysql/show', [
            'tableName' => $table,
            'columns' => $columns,
            'data' => $data,
            'filters' => $request->only('search', 'sort_by', 'sort_dir', 'column_filters')
        ]);
    }

    public function export(Request $request, $table)
    {
        if (!Schema::hasTable($table)) {
            abort(404);
        }

        $columns = Schema::getColumnListing($table);
        $search = $request->input('search');
        $sortBy = $request->input('sort_by');
        $sortDir = $request->input('sort_dir', 'asc');
        $columnFilters = $request->input('column_filters', []);

        $query = DB::table($table);
        $query = $this->applyFilters($query, $columns, $search, $columnFilters);

        if ($sortBy && in_array($sortBy, $columns)) {
            $query->orderBy($sortBy, $sortDir === 'desc' ? 'desc' : 'asc');
        }

        $headers = [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => "attachment; filename=\"{$table}_export.csv\"",
            'Pragma' => 'no-cache',
            'Cache-Control' => 'must-revalidate, post-check=0, pre-check=0',
            'Expires' => '0',
        ];

        $callback = function () use ($query, $columns) {
            $file = fopen('php://output', 'w');
            fputcsv($file, $columns);

            $query->chunk(1000, function ($records) use ($file, $columns) {
                foreach ($records as $record) {
                    $row = [];
                    foreach ($columns as $col) {
                        $row[] = $record->{$col};
                    }
                    fputcsv($file, $row);
                }
            });

            fclose($file);
        };

        return response()->stream($callback, 200, $headers);
    }

    public function query()
    {
        return Inertia::render('mysql/query');
    }

    public function execute(Request $request)
    {
        $request->validate([
            'query' => 'required|string',
        ]);

        $sql = trim($request->input('query'));

        try {
            if (!preg_match('/^select\s/i', $sql)) {
                throw new \Exception('Only SELECT queries are allowed for safety.');
            }

            $results = DB::select($sql);
            
            $columns = [];
            if (count($results) > 0) {
                $columns = array_keys((array) $results[0]);
            }

            return back()->with([
                'query_results' => [
                    'columns' => $columns,
                    'data' => $results,
                ]
            ]);
        } catch (\Exception $e) {
            return back()->withErrors(['query' => $e->getMessage()]);
        }
    }
}
