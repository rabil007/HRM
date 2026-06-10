<?php

namespace App\Http\Controllers;

use App\Support\Logging\ApplicationLogReader;
use App\Support\Pagination\ResolvesPerPage;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

class ApplicationLogController extends Controller
{
    use ResolvesPerPage;

    public function __invoke(Request $request, ApplicationLogReader $reader): Response
    {
        $validated = $request->validate([
            'file' => ['nullable', 'string', 'max:255'],
            'level' => ['nullable', 'string', 'in:'.implode(',', $reader->levels())],
            'q' => ['nullable', 'string', 'max:200'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        $perPage = $this->resolvePerPage($request, default: 50, allowed: [25, 50, 100]);

        try {
            $result = $reader->paginate(
                $validated['file'] ?? null,
                $validated['level'] ?? null,
                $validated['q'] ?? null,
                (int) ($validated['page'] ?? 1),
                $perPage,
            );
        } catch (RuntimeException) {
            abort(404);
        }

        return Inertia::render('log', [
            'entries' => $result['entries'],
            'pagination' => $result['pagination'],
            'files' => $reader->listFiles(),
            'levels' => $reader->levels(),
            'filters' => [
                'file' => $result['file']['name'],
                'level' => $validated['level'] ?? '',
                'q' => $validated['q'] ?? '',
            ],
            'file_meta' => $result['file'],
        ]);
    }
}
