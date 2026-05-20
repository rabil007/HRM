<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Http\UploadedFile;

class CsvImportFile implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! $value instanceof UploadedFile) {
            $fail('The uploaded file is invalid.');

            return;
        }

        $extension = mb_strtolower((string) $value->getClientOriginalExtension());

        if (! in_array($extension, ['csv', 'txt'], true)) {
            $fail('The file must be a .csv file.');
        }
    }
}
