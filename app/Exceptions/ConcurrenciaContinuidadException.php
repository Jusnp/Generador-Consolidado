<?php

namespace App\Exceptions;

use Exception;

/**
 * Otra carga confirmó cambios del mismo paciente entre la lectura y la
 * confirmación, o se intentó reconocer dos veces el mismo bloque.
 */
class ConcurrenciaContinuidadException extends Exception {}
