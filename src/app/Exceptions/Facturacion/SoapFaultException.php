<?php
namespace App\Exceptions\Facturacion;

use App\Exceptions\Facturacion\SINException;

class SoapFaultException extends SINException
{
    public function status(): int
    {
        return 4003;
    }

    public function errorCode(): string
    {
        return 'servicio de impuestos no disponible codigo de error 995';
    }
}
