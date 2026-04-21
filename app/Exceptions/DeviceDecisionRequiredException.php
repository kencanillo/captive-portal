<?php

namespace App\Exceptions;

use RuntimeException;

class DeviceDecisionRequiredException extends RuntimeException
{
    public function __construct(
        private readonly array $decision,
        string $message = 'Additional device action is required before this session can continue.'
    ) {
        parent::__construct($message);
    }

    public function decision(): array
    {
        return $this->decision;
    }
}
