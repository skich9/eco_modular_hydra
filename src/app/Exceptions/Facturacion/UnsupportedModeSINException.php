<?php
namespace App\Exceptions\Facturacion;

use App\Exceptions\Facturacion\SINException;

class UnsupportedModeSINException extends SINException
{
    public function status(): int
    {
        return 4005;
    }

    public function errorCode(): string
    {
        return 'Modalidad facturacion no soportada, contacte con el administrador';
    }
}
