# Docker + PostgreSQL (entorno principal de SIRUTA)

SIRUTA se ejecuta en Docker con PostgreSQL y se usa en **http://localhost:8000**, con inicio de sesión por
Authentik (`https://auth.comitedeestudiosmedicos.com.co`). El `.env` y la base MariaDB de Laragon no se usan
ni se modifican.

| Servicio   | Imagen                    | Función                              | Acceso                    |
|------------|---------------------------|--------------------------------------|---------------------------|
| `web`      | `nginx:1.27.5-alpine`     | Servidor HTTP                        | http://localhost:8000     |
| `app`      | `json-excel-app:dev`      | PHP 8.3 FPM + Laravel                | solo red interna          |
| `postgres` | `postgres:16.10-bookworm` | Base `json_excel_dev`                | solo red interna (`postgres:5432`) |

Volúmenes: `json_excel_pgdata` (datos PostgreSQL) y `json_excel_storage` (logs, sesiones, Excel generados).
Red: `json_excel_net`. Los tres servicios usan `restart: unless-stopped`: vuelven solos al abrir Docker Desktop
si estaban encendidos al cerrarlo. Para que también arranquen al encender Windows, activar en Docker Desktop
*Settings → General → Start Docker Desktop when you sign in*.

## 1. Iniciar Docker Desktop

Abrir **Docker Desktop** y esperar a *Engine running*:

```powershell
docker info --format '{{.ServerVersion}}'
```

## 2. Archivos de configuración (solo la primera vez)

- `.env.docker`: lo lee Laravel como su `.env`. Copiar los `AUTHENTIK_*` y la `APP_KEY` del `.env` de Laragon
  (la misma `APP_KEY` mantiene los seudónimos `LM-…` de los informes de La María). Copiarlos con PowerShell o un
  editor, nunca con comandos que impriman el valor.
- `compose.override.yaml` (no versionado): montajes propios del equipo. Monta en solo lectura la carpeta de los
  informes administrativos de La María en `/srv/siruta/informes-admin`, y `.env.docker` apunta
  `LA_MARIA_INFORME_ADMIN_PROSTATA` / `_CERVIX` a esos archivos. Si el informe cambia de carpeta, ajustar ambos y
  `docker compose up -d app`.
- `docker/secrets/postgres_password.txt`: clave del rol PostgreSQL (secret de Docker, no queda en variables de
  entorno). Debe ser igual a `DB_PASSWORD` de `.env.docker`.

Ambos están en `.gitignore`. En PowerShell (genera la clave sin mostrarla y restringe la lectura como el `.env`
de Laragon):

```powershell
Copy-Item .env.docker.example .env.docker
New-Item -ItemType Directory -Force docker\secrets | Out-Null
$yo = "${env:USERDOMAIN}\${env:USERNAME}"
icacls docker\secrets /inheritance:r /grant:r "${yo}:(OI)(CI)(F)" "*S-1-5-18:(OI)(CI)(F)" "*S-1-5-32-544:(OI)(CI)(F)" | Out-Null
icacls .env.docker /inheritance:r /grant:r "${yo}:(F)" "*S-1-5-18:(F)" "*S-1-5-32-544:(F)" | Out-Null
$b = New-Object byte[] 24; [Security.Cryptography.RandomNumberGenerator]::Create().GetBytes($b)
$clave = -join ($b | ForEach-Object { $_.ToString('x2') })
[IO.File]::WriteAllText("$PWD\docker\secrets\postgres_password.txt", $clave)
$env_ = [IO.File]::ReadAllText("$PWD\.env.docker") -replace '(?m)^DB_PASSWORD=.*$', "DB_PASSWORD=$clave"
[IO.File]::WriteAllText("$PWD\.env.docker", $env_, (New-Object Text.UTF8Encoding $false))
Remove-Variable b, clave, env_
```

Para **rotar** la clave más adelante, sin perder datos: `php docker/postgres/rotar_clave.php .` desde PowerShell.
Envía a PostgreSQL solo el verificador SCRAM, actualiza ambos archivos y comprueba que la clave nueva entra y la
anterior es rechazada. Después: `docker compose up -d postgres`.

## 3. Construir y levantar

```powershell
docker compose build
docker compose up -d
docker compose ps
```

Primera vez (base vacía): crear el esquema **solo** en la base de Docker.

```powershell
docker compose exec app php artisan db:show       # debe decir pgsql / postgres / json_excel_dev
docker compose exec app php artisan migrate
```

## 4. Inicio de sesión con Authentik

1. http://localhost:8000 → **Ingresar con Authentik**.
2. En Authentik se ingresa con el **número de cédula** y la contraseña de Authentik; regresa a
   `http://localhost:8000/auth/authentik/callback`.
3. Laravel busca el usuario **solo** por el identificador verificado (`authentik_subject`), nunca por correo. Debe
   estar activo y aprovisionado o vinculado. El rol (`admin` / `user`) sale de la tabla `users`.
4. **Cerrar sesión** cierra la sesión de SIRUTA y vuelve a `/login` (no cierra la sesión de Authentik).

**Usuarios** (solo administradores): *Nuevo usuario* pide nombre, cédula, correo opcional, rol y estado. SIRUTA crea
la cuenta en Authentik con la cédula como usuario y muestra el estado de cada cuenta. Acciones:

- *Enlace de activación*: la persona define su propia contraseña (requiere flujo de recuperación en la brand).
- *Reintentar aprovisionamiento*: retoma un intento fallido sin duplicar cuentas.
- *Vincular cuenta existente*: cuando la cédula ya existe en Authentik en una cuenta ajena; no la modifica.
- *Desvincular Authentik*: retira solo la vinculación local.

Editar o desactivar nunca cambia Authentik, que es compartido. Detalle, estados y requisitos del token en
`docs/RIESGO_VINCULACION_AUTHENTIK.md`. Diagnóstico de solo lectura:

```powershell
docker compose exec app php artisan authentik:diagnostico --cedula=<cédula>
```

## 5. Comprobar PostgreSQL

```powershell
docker compose exec postgres psql -U json_excel -d json_excel_dev -c 'select version();'
docker compose exec app php artisan migrate:status
```

PostgreSQL no se publica en Windows; las consultas manuales se hacen con `psql` dentro del contenedor.

## 6. Artisan y pruebas dentro del contenedor

```powershell
docker compose exec app php artisan route:list --except-vendor
docker compose exec app php artisan test --compact tests/Feature/ProgramaNavigationTest.php
```

Las pruebas usan SQLite en memoria (`phpunit.xml`) y no tocan PostgreSQL ni el Authentik real. No agregar
variables `DB_*` en `compose.yaml` para el servicio `app`: PHPUnit no sobrescribe variables de entorno reales y
las pruebas con `RefreshDatabase` vaciarían la base de Docker.

El código (`app`, `config`, `database`, `public`, `resources`, `routes`, `tests`) se monta en **solo lectura**:
los cambios hechos en Windows se ven al instante, pero el contenedor no puede escribir en el proyecto.
`make:*`, Pint y Composer se ejecutan con el PHP de Windows. Tras cambiar `composer.json`/`composer.lock`,
`bootstrap/` o renombrar/eliminar clases: `docker compose build app; docker compose up -d app`.
Tras editar `.env.docker`: `docker compose restart app`.

El servicio `app` corre como `www-data` (igual que los workers de PHP-FPM), así que `docker compose exec app …`
escribe en `storage` con el dueño correcto. No usar `-u root` para comandos de Laravel: archivos de root en
`storage` (por ejemplo `laravel.log` o `storage/app/generated`) provocan «could not be opened in append mode:
Permission denied» y que falle la generación de Excel. `docker compose cp` hacia el contenedor también crea
archivos de root: no copiar a `storage`. Si el error reaparece:

```powershell
docker compose exec -u root app chown -R www-data:www-data /var/www/html/storage
```

## 7. Detener sin borrar datos

```powershell
docker compose stop        # detiene; no vuelven solos hasta `docker compose up -d` o `start`
docker compose down        # elimina contenedores y red; los volúmenes (datos) se conservan
```

**No usar** `docker compose down -v`, `docker volume prune` ni `docker system prune`: borran la base PostgreSQL.

## Laragon

Laragon conserva intactos su `.env`, MariaDB (3306) y sus datos, pero SIRUTA ya no depende de ellos.
`php artisan serve` no debe iniciarse para SIRUTA: el puerto 8000 lo ocupa Docker y Laravel saltaría al 8001.
Otros proyectos que usen `artisan serve` en el 8000 (Repository_Medellin) también se moverán al 8001
mientras Docker esté encendido.

## Limitaciones conocidas

- **Datos de MariaDB:** trasladados el 2026-10-30 (usuarios sin contraseñas, catálogos, CUPS, notas técnicas,
  tarifas, homologaciones, ejecuciones e historial de actividad), con huella idéntica por tabla. Detalle, respaldo y
  verificaciones en `docs/TRASLADO_MARIADB_POSTGRESQL.md`.
- **Verificado sobre PostgreSQL 16:** conexión, las 34 migraciones en base vacía, sesiones, página de inicio,
  recursos estáticos y el tramo Laravel → Authentik (cliente y URL de retorno aceptados). **No verificado:** la
  ejecución económica completa con datos reales, ni `migrate:rollback`.
- **Diferencias de motor a revisar:**
  - `LIKE` distingue mayúsculas en PostgreSQL (MariaDB no). Afecta la búsqueda de `CatalogoReferenciaController`.
  - `AlmacenContinuidadHospitalariaBaseDatos` usa `GET_LOCK`/`RELEASE_LOCK` solo con MySQL; en PostgreSQL
    omite ese bloqueo entre cargas concurrentes.
  - PostgreSQL no convierte tipos implícitamente (texto contra número) como MariaDB.
- **Informes administrativos de La María:** se leen de la carpeta montada en solo lectura (ver
  `compose.override.yaml`). Si el archivo no está, la exportación se detiene con un mensaje claro; también se
  puede cargar el informe en el formulario.
- La interfaz no usa Vite (`@vite` no aparece en las vistas): CSS/JS se sirven desde `public/`, sin `npm`.
