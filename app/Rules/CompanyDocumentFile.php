<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Http\UploadedFile;
use Illuminate\Translation\PotentiallyTranslatedString;

class CompanyDocumentFile implements ValidationRule
{
    /**
     * Run the validation rule.
     *
     * @param  Closure(string, ?string=): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! $value instanceof UploadedFile || ! $value->isValid()) {
            $fail('The :attribute must be a valid uploaded file.');

            return;
        }

        $realPath = $value->getRealPath();

        if (! is_string($realPath)) {
            $fail('The :attribute could not be read.');

            return;
        }

        $mimeType = (new \finfo(FILEINFO_MIME_TYPE))->file($realPath);

        if (! in_array($mimeType, ['application/pdf', 'image/jpeg', 'image/png'], true)) {
            $fail('The :attribute must contain a PDF, JPG, JPEG, or PNG file.');
        }
    }
}
