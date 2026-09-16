<?php

namespace App\Http\Controllers\Organization;

use App\Http\Controllers\Controller;
use App\Models\Position;
use App\Support\Positions\PositionAttachmentStorage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PositionAttachmentController extends Controller
{
    public function preview(Request $request, Position $position): StreamedResponse
    {
        return $this->respond($request, $position, true);
    }

    public function download(Request $request, Position $position): StreamedResponse
    {
        return $this->respond($request, $position, false);
    }

    private function respond(Request $request, Position $position, bool $inline): StreamedResponse
    {
        $companyId = (int) $request->attributes->get('current_company_id');
        abort_unless((int) $position->company_id === $companyId, 404);

        $path = PositionAttachmentStorage::validatedRelativePath(
            $position->attachment_path,
            $companyId,
            (int) $position->id,
        );

        abort_unless($path !== null && Storage::disk(PositionAttachmentStorage::DISK)->exists($path), 404);

        $filename = $position->attachment_original_name ?: basename($path);

        if (! $inline) {
            return Storage::disk(PositionAttachmentStorage::DISK)->download($path, $filename);
        }

        return Storage::disk(PositionAttachmentStorage::DISK)->response($path, $filename, [
            'Content-Type' => $position->attachment_mime_type ?: 'application/octet-stream',
            'Content-Disposition' => 'inline; filename="'.str_replace('"', '', $filename).'"',
        ]);
    }
}
