<?php

namespace App\Support\Documents\Exceptions;

use RuntimeException;

class DocumentTemplateSourceUnavailableException extends RuntimeException
{
    public function __construct(string $message = 'Template source PDF is not available.')
    {
        parent::__construct($message);
    }
}
