<?php

namespace App\Support\Documents;

use Illuminate\Validation\ValidationException;

final class RejectInvalidDocumentTemplateLayout
{
    public const CODE = 'TEMPLATE_LAYOUT_INVALID';

    public static function throw(DocumentTemplateLayoutPreflightResult $result): never
    {
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
            'errors' => [
                'layout' => [$headline],
            ],
        ], 422);

        throw $exception;
    }
}
