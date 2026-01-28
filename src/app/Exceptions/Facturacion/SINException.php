<?php

namespace App\Exceptions\Facturacion;

use App\Exceptions\ApplicationException;

abstract class SINException extends ApplicationException
{
    public function status(): int
    {
        return 4000;
    }

    public function errorCode(): string
    {
        return 'application_error';
    }
}
