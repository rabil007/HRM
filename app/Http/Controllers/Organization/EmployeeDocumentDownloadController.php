<?php

namespace App\Http\Controllers\Organization;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\EmployeeDocument;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;
use ZipArchive;

class EmployeeDocumentDownloadController extends Controller
{
    public function __invoke(Request $request, Employee $employee): StreamedResponse
    {
        $companyId = (int) $request->attributes->get('current_company_id');

        abort_unless($employee->company_id === $companyId, 403);

        $documents = EmployeeDocument::query()
            ->where('employee_id', $employee->id)
            ->where('company_id', $companyId)
            ->orderBy('created_at')
            ->get(['id', 'file_path', 'original_filename', 'document_type', 'document_type_id', 'title']);

        abort_if($documents->isEmpty(), 404, 'No documents found for this employee.');

        $tmpPath = tempnam(sys_get_temp_dir(), 'docs_');

        $zip = new ZipArchive;

        abort_unless($zip->open($tmpPath, ZipArchive::OVERWRITE) === true, 500, 'Could not create archive.');

        $usedNames = [];

        foreach ($documents as $doc) {
            $isExternal = str_starts_with((string) $doc->file_path, 'http');
            $label = $doc->title ?? $doc->document_type ?? "document_{$doc->id}";
            $ext = pathinfo((string) ($doc->original_filename ?? $doc->file_path), PATHINFO_EXTENSION);
            $basename = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $label);
            $filename = "{$basename}.{$ext}";

            // Deduplicate filenames within the zip
            if (isset($usedNames[$filename])) {
                $usedNames[$filename]++;
                $filename = "{$basename}_{$usedNames[$filename]}.{$ext}";
            } else {
                $usedNames[$filename] = 1;
            }

            if ($isExternal) {
                $contents = @file_get_contents((string) $doc->file_path);

                if ($contents !== false) {
                    $zip->addFromString($filename, $contents);
                }
            } else {
                $absolutePath = Storage::disk('public')->path((string) $doc->file_path);

                if (file_exists($absolutePath)) {
                    $zip->addFile($absolutePath, $filename);
                }
            }
        }

        $zip->close();

        $slug = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $employee->name ?? 'employee');
        $downloadName = "documents_{$slug}.zip";

        return response()->streamDownload(function () use ($tmpPath) {
            readfile($tmpPath);
            @unlink($tmpPath);
        }, $downloadName, [
            'Content-Type' => 'application/zip',
            'Content-Length' => filesize($tmpPath),
        ]);
    }
}
