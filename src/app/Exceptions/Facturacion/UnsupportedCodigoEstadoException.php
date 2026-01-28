<?php
namespace App\Exceptions\Facturacion;

use App\Exceptions\Facturacion\SINException;

class UnsupportedCodigoEstadoException extends SINException
{
    public function status(): int
    {
        return 4004;
    }

    public function errorCode(): string
    {
        return 'servicio de impuestos no disponible codigo de error 995';
    }
}
