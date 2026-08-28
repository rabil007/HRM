<?php

namespace App\Exceptions;

use Exception;
use Throwable;

class DocumentWorkflowException extends Exception
{
    public function __construct(
        string $message,
        public readonly string $errorCode = 'document_workflow_error',
        int $code = 0,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $code, $previous);
    }

    public static function make(
        string $message,
        string $errorCode = 'document_workflow_error',
        ?Throwable $previous = null,
    ): self {
        return new self($message, $errorCode, 0, $previous);
    }
}
