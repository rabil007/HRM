<?php

namespace App\Exceptions;

use Exception;
use Throwable;

class CrewMovementException extends Exception
{
    public function __construct(
        string $message,
        public readonly string $errorCode = 'crew_movement_error',
        int $code = 0,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $code, $previous);
    }

    public static function make(
        string $message,
        string $errorCode = 'crew_movement_error',
        ?Throwable $previous = null,
    ): self {
        return new self($message, $errorCode, 0, $previous);
    }
}
