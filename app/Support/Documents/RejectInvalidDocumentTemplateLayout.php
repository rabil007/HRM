<?php

namespace App\Support\Documents;

use Illuminate\Validation\ValidationException;

final class RejectInvalidDocumentTemplateLayout
{
    public const CODE = 'TEMPLATE_LAYOUT_INVALID';

    public const UNAVAILABLE_CODE = PdfOverlayLayoutPreflight::CODE_LAYOUT_VALIDATION_UNAVAILABLE;

    public static function throw(DocumentTemplateLayoutPreflightResult $result): never
    {
        if ($result->isUnavailable()) {
            self::throwUnavailable($result);
        }

        $issues = $result->blockingIssues();
        $count = count($issues);
        $headline = $count === 1
            ? 'This template has 1 layout issue that must be fixed before publishing.'
            : "This template has {$count} layout issues that must be fixed before publishing.";

        $exception = ValidationException::withMessages([
            'layout' => $headline,
        ]);
        $exception->response = response()->json([
            'code' => self::CODE,
            'message' => $headline,
            'issues' => $issues,
            'reference' => $result->reference,
            'errors' => [
                'layout' => [$headline],
            ],
        ], 422);

        throw $exception;
    }

    public static function throwUnavailable(DocumentTemplateLayoutPreflightResult $result): never
    {
        $headline = 'Layout validation could not be completed. Publishing is unavailable until the validation check succeeds.';
        $issues = $result->blockingIssues();
        $exception = ValidationException::withMessages([
            'layout' => $headline,
        ]);
        $exception->response = response()->json([
            'code' => self::UNAVAILABLE_CODE,
            'message' => 'Layout validation could not be completed. Try again.',
            'reference' => $result->reference,
            'issues' => $issues,
            'errors' => [
                'layout' => [$headline],
            ],
        ], 422);

        throw $exception;
    }
}
