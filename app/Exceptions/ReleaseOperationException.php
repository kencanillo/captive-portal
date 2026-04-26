<?php

namespace App\Exceptions;

use RuntimeException;

class ReleaseOperationException extends RuntimeException
{
    public const KIND_CONFIGURATION = 'configuration';
    public const KIND_VALIDATION = 'validation';
    public const KIND_POLICY = 'policy';

    public function __construct(
        public readonly string $kind,
        string $message,
    ) {
        parent::__construct($message);
    }

    public static function configuration(string $message): self
    {
        return new self(self::KIND_CONFIGURATION, $message);
    }

    public static function validation(string $message): self
    {
        return new self(self::KIND_VALIDATION, $message);
    }

    public static function policy(string $message): self
    {
        return new self(self::KIND_POLICY, $message);
    }
}
