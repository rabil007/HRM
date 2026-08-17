<?php

namespace App\Http\Controllers;

use App\Support\Logging\ApplicationLogReader;
use App\Support\Pagination\ResolvesPerPage;
use App\Support\Platform\PlatformAudit;
use App\Support\Platform\PlatformAuthorization;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ApplicationLogController extends Controller
{
    use ResolvesPerPage;

    public function index(Request $request, ApplicationLogReader $reader): Response
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
            'can' => [
                'manage' => PlatformAuthorization::canManage($request->user()),
            ],
        ]);
    }

    public function export(Request $request, ApplicationLogReader $reader): BinaryFileResponse|RedirectResponse
    {
        $validated = $request->validate([
            'file' => ['nullable', 'string', 'max:255'],
        ]);

        try {
            $files = $reader->listFiles();

            if ($files === []) {
                return back()->with('error', 'No log files to export.');
            }

            $fileName = $validated['file'] ?? $files[0]['name'];
            $path = $reader->resolvePath($fileName);

            $downloadName = basename($path);
            if (str_ends_with($downloadName, '.log')) {
                $downloadName = substr($downloadName, 0, -4).'.txt';
            } else {
                $downloadName .= '.txt';
            }

            PlatformAudit::record($request->user(), 'Exported application logs', [
                'action' => 'platform.logs.export',
                'file' => $fileName,
            ]);

            return response()->download($path, $downloadName, [
                'Content-Type' => 'text/plain',
            ]);
        } catch (RuntimeException) {
            abort(404);
        }
    }

    public function destroy(Request $request, ApplicationLogReader $reader): RedirectResponse
    {
        $validated = $request->validate([
            'file' => ['nullable', 'string', 'max:255'],
            'scope' => ['required', 'in:current,all'],
        ]);

        try {
            if ($validated['scope'] === 'all') {
                $cleared = $reader->clearAll();

                if ($cleared === 0) {
                    return back()->with('error', 'No log files to clear.');
                }

                PlatformAudit::record($request->user(), 'Cleared application logs', [
                    'action' => 'platform.logs.clear_all',
                    'files_cleared' => $cleared,
                ]);

                $label = $cleared === 1 ? '1 log file' : "{$cleared} log files";

                return redirect()
                    ->route('log')
                    ->with('success', "Cleared {$label}.");
            }

            $files = $reader->listFiles();

            if ($files === []) {
                return back()->with('error', 'No log files to clear.');
            }

            $fileName = $validated['file'] ?? $files[0]['name'];
            $reader->clearFile($fileName);

            PlatformAudit::record($request->user(), 'Cleared application logs', [
                'action' => 'platform.logs.clear_file',
                'file' => $fileName,
            ]);

            return redirect()
                ->route('log', array_filter([
                    'file' => $fileName,
                    'level' => $request->string('level')->toString() ?: null,
                    'q' => $request->string('q')->toString() ?: null,
                ]))
                ->with('success', "Cleared {$fileName}.");
        } catch (RuntimeException) {
            abort(404);
        }
    }
}
