<?php

namespace App\Exceptions;

use Exception;

abstract class ApplicationException extends Exception
{
    public function status(): int
    {
        return 400;
    }

    public function errorCode(): string
    {
        return 'application_error';
    }
}
