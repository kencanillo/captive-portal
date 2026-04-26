<?php

namespace App\Exceptions;

use RuntimeException;
use Throwable;

class OmadaOperationException extends RuntimeException
{
    public const CATEGORY_TIMEOUT = 'timeout';
    public const CATEGORY_AUTHENTICATION = 'authentication';
    public const CATEGORY_SSL = 'ssl';
    public const CATEGORY_CONTROLLER = 'controller';
    public const CATEGORY_CONFIGURATION = 'configuration';
    public const CATEGORY_VALIDATION = 'validation';

    public function __construct(
        public readonly string $category,
        string $message,
        public readonly ?int $httpStatus = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
