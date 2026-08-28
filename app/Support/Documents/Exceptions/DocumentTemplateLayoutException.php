<?php

namespace App\Support\Documents\Exceptions;

use RuntimeException;

class DocumentTemplateLayoutException extends RuntimeException
{
    public function __construct(
        public readonly string $fieldKey,
        public readonly int $pageNumber = 1,
        string $message = '',
    ) {
        $msg = $message !== ''
            ? $message
            : "The field '{$fieldKey}' on page {$pageNumber} exceeds placement boundaries.";

        parent::__construct($msg);
    }
}
