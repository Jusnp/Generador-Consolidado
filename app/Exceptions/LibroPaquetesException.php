<?php

namespace App\Exceptions;

use Exception;

/**
 * El libro de paquetes persistente no puede usarse en la ejecución (ruta sin
 * punto de inicio) o su resultado no concilia con el informe.
 */
class LibroPaquetesException extends Exception {}
