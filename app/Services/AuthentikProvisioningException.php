<?php

namespace App\Services;

use RuntimeException;

/**
 * Falla de la integración con Authentik. El mensaje se muestra al
 * administrador y se guarda en el usuario: nunca incluye tokens, enlaces,
 * contraseñas ni la respuesta cruda de Authentik.
 */
class AuthentikProvisioningException extends RuntimeException
{
    //
}
