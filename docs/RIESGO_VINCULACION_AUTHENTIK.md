# Identidades de SIRUTA en Authentik

Estado al 2026-11-04: **corregido**. Antes, si SIRUTA no reconocía el `sub` de Authentik, buscaba un usuario por
correo y sobrescribía su `authentik_subject`. Como el correo no es único en el Authentik compartido, otra cuenta
podía apropiarse de un usuario de SIRUTA y de su rol.

## Cómo identifica SIRUTA al usuario

`App\Services\AuthentikAuthenticator::activeUserForClaims()`:

1. Busca el usuario **solo** por `authentik_subject = sub` (el `uid` de Authentik, modo *hashed user ID*).
2. Nunca busca ni vincula por correo, y nunca escribe `authentik_subject` durante el inicio de sesión.
3. Exige usuario activo en SIRUTA y estado `provisioned` o `linked`.
4. El rol sale de la tabla `users`; los claims (`groups`, `is_superuser`, etc.) se ignoran.
5. No crea usuarios. Cada rechazo queda en el registro de actividad como `login_authentik_rejected`.

`App\Http\Middleware\EnsureUserIsActive` cierra en la siguiente petición la sesión de un usuario desactivado, y
desactivar o desvincular un usuario borra solo sus sesiones locales.

## Cómo se vinculan las identidades

| Estado | Significado | Puede ingresar |
|---|---|---|
| `unlinked` | Sin cuenta en Authentik | No |
| `pending` / `failed` | Aprovisionamiento incompleto; se puede reintentar | No |
| `provisioned` | SIRUTA creó la cuenta (username = cédula) | Sí |
| `linked` | Cuenta preexistente vinculada por un administrador, sin modificarla | Sí |
| `conflict` | La cédula existe en Authentik en una cuenta que SIRUTA no creó | No |

- **Creación** (`AuthentikUserProvisioner::provision`): se guarda el usuario local con una clave de
  aprovisionamiento, se busca la cédula en Authentik y, si no existe, se crea una cuenta *internal* con esa clave en
  `attributes.siruta`. Un reintento solo adopta la cuenta que lleva la clave de ese usuario: nunca duplica ni se
  apropia de cuentas ajenas. La contraseña nunca la define SIRUTA.
- **Activación**: enlace temporal del flujo de recuperación de Authentik, solo para cuentas creadas por SIRUTA que
  nunca iniciaron sesión. Se muestra una vez y no se guarda en sesión ni en registros.
- **Vinculación administrativa** (`linkExisting`): para una cédula en conflicto, un administrador confirma
  escribiendo la cédula. Se rechazan superusuarios, cuentas no internas, inactivas o ya vinculadas a otro usuario.
  La cuenta de Authentik no se modifica.
- **Edición, desactivación y desvinculación** solo cambian SIRUTA; nunca desactivan ni borran cuentas de Authentik,
  que pueden usarse en otras aplicaciones.

Pruebas: `AuthentikAuthenticationTest`, `AuthentikUserProvisioningTest`, `AuthentikDiagnosticoTest` y
`UserAccessControlTest` (en `tests/Feature`).

## Requisitos en Authentik (los configura su administrador)

- **Token exclusivo de SIRUTA.** Debe pertenecer a una cuenta de servicio propia de SIRUTA, sin superusuario y con
  solo `view_user`, `add_user` y `reset_user_password` (y `add_user_to_group` sobre el grupo de SIRUTA si se usa
  `AUTHENTIK_ACCESS_GROUP`). No reutilizar el token de una cuenta de otra aplicación: hereda sus permisos (por
  ejemplo, editar cualquier usuario) y queda atado a su ciclo de vida. Cambiar de token es reemplazar
  `AUTHENTIK_API_TOKEN` y reiniciar `app`; no exige tocar los tokens de otros sistemas.
- **Flujo de recuperación** asignado a la marca (brand). Sin él no se generan enlaces de activación.
- **Opcional:** un grupo exclusivo de SIRUTA ligado a la aplicación SIRUTA. Hoy Authentik deja pasar a cualquier
  cuenta y el único control es la vinculación en SIRUTA. SIRUTA verifica que ni el grupo ni sus padres otorguen
  superusuario antes de asignarlo.

Diagnóstico de solo lectura: `php artisan authentik:diagnostico --cedula=<cédula>`.

## Credenciales del historial antiguo

El historial de Git anterior a la copia segura incluye un seeder con un usuario administrador local y su
contraseña en texto plano. En esta distribución el seeder está neutralizado. Esa contraseña debe tratarse como
comprometida: no reutilizarla en ninguna cuenta (SIRUTA, Authentik, bases de datos) y no publicar ese historial.
Con Authentik habilitado, el inicio de sesión local de SIRUTA responde 404, así que la contraseña local no da acceso.
