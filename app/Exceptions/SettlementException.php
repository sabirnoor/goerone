<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Expected, user-facing settlement failure (validation, balance, state).
 * $httpStatus is the value placed in status.httpStatus of the API payload.
 */
class SettlementException extends RuntimeException
{
    public int $httpStatus;

    public function __construct(string $message, int $httpStatus = 422)
    {
        parent::__construct($message);
        $this->httpStatus = $httpStatus;
    }
}