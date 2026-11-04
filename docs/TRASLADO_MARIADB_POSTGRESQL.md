# Traslado de datos MariaDB → PostgreSQL (SIRUTA)

Estado: **ejecutado el 2026-10-30** con autorización (resultado en la sección 8). MariaDB solo se ha leído.

## 1. Inventario verificado

Conteos exactos de `php docker/postgres/traslado/trasladar.php verificar`:

| Tabla | MariaDB | PostgreSQL | Programa que la usa | Necesaria para operar |
|-------|--------:|-----------:|---------------------|-----------------------|
| `users` | 5 | 5 (IDÉNTICA) | todos | ya copiada |
| `catalogo_referencias` | 14 | 0 | La María; Autoinmunes (CUM) | sí |
| `catalogo_referencia_items` | 391 935 | 0 | La María; Autoinmunes (CUM) | sí |
| `codigo_cups` | 387 | 0 | Autoinmunes | sí |
| `codigo_medicamentos` | 986 | 34 (semilla) | Autoinmunes | sí |
| `codigo_medicamentos_nt` | 424 | 0 | Autoinmunes | sí |
| `codigo_insumos_nt` | 45 | 0 | Autoinmunes | sí |
| `medication_adjustments` | 267 | 0 | Autoinmunes | sí |
| `medication_service_classifications` | 763 | 0 | Autoinmunes | sí |
| `activity_logs` | 336 | 0 | todos (estado funcional de catálogos y continuidad) | sí |
| `programa_ejecuciones` | 87 | 0 | La María (historial, padre de FK) | historial |
| `catalogo_consolidado_imports` | 4 | 0 | administración | auditoría |
| `contratos`, `referencias_manuales`, `ah_antecedentes`, `ah_estancias_abiertas` | 0 | 0 | — | vacías |

- **Salud Mental** no usa tablas ni archivos: es un programa pendiente.
- **Catálogos activos de La María** (uno activo por tipo): ANEXOS_2706 (26 037 ítems activos), MEDICAMENTOS_CUM
  (155 807), NOTA_MEDICAMENTOS (10 789), DISPENSACION_PROSTATA (13 960), DISPENSACION_CERVIX (7 620),
  NOTA_PROSTATA (298), NOTA_CERVIX (436), COMPLEMENTO_MEDICAMENTOS_PROSTATA (36).
- **No se trasladan:** `sessions`, `cache*`, `jobs*`, `failed_jobs`, `password_reset_tokens`, `migrations`
  (PostgreSQL tiene las suyas) y `referencia_manuals` (tabla antigua, 0 filas).
- `hospital_segmentos` y `hospital_paquete_movimientos` existen solo en PostgreSQL: MariaDB nunca aplicó esas
  migraciones. Solo se usan con `LA_MARIA_LIBRO_PAQUETES=PERSISTENTE`, que no está activo.

Sumas de control en MariaDB (deben repetirse en PostgreSQL tras el traslado):

| Columna | Suma |
|---------|-----:|
| `catalogo_referencia_items.tarifa_referencia` | 23 797 466 478,9380 |
| `codigo_cups.tarifa_2025` | 94 426 196,3373 |
| `codigo_medicamentos.tarifa_unitario` | 51 817 285,160000 |
| `codigo_medicamentos_nt.tarifa_nt` | 50 322 043,523000 |
| `codigo_insumos_nt.tarifa_unitario` | 633 683,0000 |
| `medication_adjustments.valor_total_override` | 0,000000 |

## 2. Comparación de esquemas

Ambos esquemas salen de las mismas migraciones. Diferencias (todas compatibles, PostgreSQL es igual o más ancho):

- `codigo_medicamentos_nt.cums` varchar(30) → varchar(100); `pertenece_nt` varchar(10) → varchar(20).
- `codigo_medicamentos_nt.tarifa_nt` decimal(15,4) → numeric(20,6): mismo valor, más escala.
- `programa_ejecuciones.batch_id` char(36) → uuid: los 87 valores son uuid válidos.
- JSON: PostgreSQL usa `json` (conserva el texto exacto; `jsonb` rompería los hashes de `ah_*` y la traza de ítems).

## 3. Controles del procedimiento

`docker/postgres/traslado/trasladar.php`:

- **Origen intacto:** MariaDB se lee en `START TRANSACTION WITH CONSISTENT SNAPSHOT, READ ONLY` y termina en
  `ROLLBACK`; el servidor rechaza cualquier escritura.
- **Antes de copiar** valida cada valor contra el tipo destino: booleanos 0/1, uuid, fechas (sin `0000-00-00`),
  JSON válido, decimales que caben en su precisión, enteros en rango de `smallint`/`integer`, longitudes, bytes
  nulos, NOT NULL, huérfanos de cada clave foránea y duplicados de cada índice único de PostgreSQL.
  Resultado actual: **sin bloqueos**.
- **Ids y relaciones:** copia con los mismos `id`, en orden de id y de claves foráneas, y ajusta cada secuencia.
- **Decimales exactos:** viajan como texto en `COPY` (nunca como float).
- **Sin duplicados ni sobrescrituras:** se niega si una tabla destino tiene filas distintas de las validadas.
- **Una sola transacción:** `COPY` de todas las tablas, comprobación de conteo y huella SHA-256 de cada tabla
  contra MariaDB, y recién entonces `COMMIT`. Si algo difiere, PostgreSQL queda como estaba.
- **Huella por tabla:** SHA-256 de cada fila en forma canónica sin ambigüedad (`v<largo>:<valor>`, `n` = nulo;
  decimales sin ceros finales; fechas `AAAA-MM-DD HH:MM:SS`), encadenada en orden de id. La tabla `users`
  ya copiada da la misma huella en ambos motores; un ensayo con filas sintéticas (tabuladores, saltos de línea,
  `\`, `\N` literal, separadores, acentos, JSON, uuid en mayúsculas, nulos, decimales con ceros) en una tabla
  temporal con `ROLLBACK` confirmó `COPY` byte a byte y huellas iguales.

### Caso especial: semilla de `codigo_medicamentos`

La migración `2026_09_10_000004` creó 34 filas (ids 1–34) en la base vacía de Docker. En MariaDB esos mismos 34
códigos existen con **otros ids**; los ids 1–34 de MariaDB son otros medicamentos (cargados el 2026-09-03).
Conservar la semilla duplicaría códigos y chocaría en ids. El procedimiento las **reemplaza** por las 986 filas de
MariaDB dentro de la transacción, solo si siguen intactas (34 filas, nunca editadas, misma huella).

## 4. Riesgos que no son de datos (comportamiento del código en PostgreSQL)

No se corrigen aquí porque tocan módulos funcionales; se documentan para decidir aparte.

1. **Lecturas sin `ORDER BY`** en La María (`ReferenciaRutaMatcher`, `HistoricoTarifarioConsolidado`,
   `ValorizacionAdministrativa`): con claves repetidas gana la primera o la última fila leída. Hay 25 claves de
   dispensación (8 Cérvix, 17 Próstata: mismo código, paciente y fecha) con tarifas distintas, y 18 896 CUM con
   metadatos distintos. **Comprobado tras el traslado:** la misma consulta devuelve otro orden en cada motor
   (MariaDB por el índice `catalogo_referencia_id, codigo`; PostgreSQL en orden físico, que es el de id). En el
   catálogo CUM activo, 924 IUM tienen varios CUM y en 505 el primer candidato cambia (solo cambia el CUM citado
   en el texto); en 1 (IUM `1D1001421009100`, dexmedetomidina: `20087299-1` frente a `20087299-2`) el candidato
   de PostgreSQL tiene precio de dispensación y el de MariaDB no, así que podría cambiar un valor si una línea de
   Próstata se resuelve por ese IUM en el mes de esa dispensación. Arreglo propuesto (requiere autorización, toca
   La María): `orderBy('codigo')->orderBy('id')` en `ReferenciaRutaMatcher.php:167`, que reproduce el orden que
   usaba MariaDB, y `orderBy('id')` en las demás lecturas sin orden.
2. **Comparaciones de texto:** MariaDB ignora mayúsculas y espacios finales; PostgreSQL no. Hoy ningún código de
   los catálogos activos ni de `codigo_*` tiene minúsculas ni espacios, así que no cambia resultados.
3. **Bloqueo entre cargas:** `GET_LOCK` solo existe en MySQL; en PostgreSQL quedan el control de versión y los
   índices únicos (solo afecta al modo PERSISTENTE, inactivo).
4. **`APP_KEY`:** genera los seudónimos `LM-…` de los Excel de La María. Docker tiene una clave distinta a la de
   Laragon, así que los seudónimos nuevos no coinciden con los de informes anteriores (los montos no cambian;
   no hay columnas cifradas).
5. **Informes administrativos de La María:** no existe catálogo `HISTORICO_TARIFARIO_LA_MARIA`, y
   `LA_MARIA_INFORMES_ADMIN_REQUERIDOS=true`; en Docker hay que cargar el informe en el formulario en cada
   ejecución, o montar la carpeta de informes y poner sus rutas en `.env.docker`.
6. **Pruebas:** `SeguimientoRutaControllerTest` escribe libros en `storage/app/generated` real (no usa
   `Storage::fake`); al ejecutarlas con el PHP de Windows aparecen archivos de prueba entre los originales.

## 5. Archivos fuera de la base

- **Originales para comparar:** los libros de `storage/app/generated` de Laragon (CONSOLIDADO y LA_MARIA) se quedan
  donde están y se leen solo para comparar. `programa_ejecuciones.archivo_salida` indica cuál corresponde a cada
  ejecución, y `activity_logs` (`seguimiento_rutas`, `files_sha256`) identifica los archivos de entrada usados.
- **No se trasladan:** `storage/app/private/json-temp` (RIPS de pacientes, restos de cargas) ni los Excel sueltos de
  `storage/app`, que solo usan los comandos de importación.

## 6. Procedimiento (una vez autorizado)

Desde PowerShell, en la raíz del proyecto, con Docker encendido y sin usar el SIRUTA de Laragon:

```powershell
# 1. Respaldo de PostgreSQL (solo cambia PostgreSQL; MariaDB no se escribe)
New-Item -ItemType Directory -Force C:\respaldos\siruta | Out-Null
docker compose exec -T postgres pg_dump -U json_excel -d json_excel_dev -Fc -f /tmp/antes_traslado.dump
docker compose cp postgres:/tmp/antes_traslado.dump C:\respaldos\siruta\json_excel_dev_antes_traslado.dump

# 2. Verificación previa: debe terminar en "Sin bloqueos"
php docker/postgres/traslado/trasladar.php verificar

# 3. Traslado en una sola transacción: debe terminar en "TRASLADO CONFIRMADO"
php docker/postgres/traslado/trasladar.php trasladar --autorizado

# 4. Verificación posterior: todas las tablas con datos deben decir IDENTICA
php docker/postgres/traslado/trasladar.php verificar
```

5. **Comparación económica:** elegir ejecuciones de referencia (por ejemplo, la última de Próstata, la última de
   Cérvix y un consolidado de Autoinmunes) y repetirlas en http://localhost:8000 con los mismos archivos de entrada
   (su SHA-256 debe coincidir con `files_sha256` del registro original). Comparar cada libro:

   ```powershell
   php docker/postgres/traslado/comparar_excel.php C:\laragon\www\json-excel\storage\app\generated\ORIGINAL.xlsx REPLICA.xlsx
   ```

   Muestra, por hoja, filas, celdas distintas y suma numérica, sin imprimir contenido. Ignora los seudónimos `LM-…`
   salvo con `--estricto`. Si los catálogos activos cambiaron después de la ejecución original, las diferencias
   reflejan ese cambio y no el traslado.

6. **Reversión:** un fallo durante el paso 3 no deja cambios. Para deshacer un traslado ya confirmado se restaura
   el respaldo del paso 1 (`pg_restore --clean`), lo que reemplaza la base de Docker y requiere autorización.

Duración estimada: verificación ~10 s; traslado de ~395 000 filas, del orden de 1–2 minutos.

## 7. Decisiones que requieren autorización

1. Ejecutar el traslado de las 15 tablas de la sección 1 (11 con datos), conservando ids.
2. Reemplazar las 34 filas de semilla de `codigo_medicamentos` por las 986 de MariaDB.
3. Incluir `activity_logs` (336 filas con ids de usuario, IP y navegador): recomendado, porque guarda estado
   funcional de catálogos y continuidad.
4. `APP_KEY` de Docker: mantener la actual (seudónimos nuevos) o usar la de Laragon (continuidad de seudónimos y
   comparación estricta).
5. Informes administrativos de La María: cargarlos en cada ejecución o montar su carpeta en el contenedor.
6. Lugar del respaldo de PostgreSQL (propuesto `C:\respaldos\siruta\`).

## 8. Resultado (2026-10-30)

- **Respaldo previo:** `C:\respaldos\siruta\json_excel_dev_antes_traslado_20261030_111206.dump` (carpeta con
  acceso solo para el usuario, SYSTEM y Administradores; SHA-256 igual en origen y destino; restaurado en una base
  temporal, idéntico a la original, y la base temporal eliminada).
- **Semilla de `codigo_medicamentos`:** comprobada fila por fila contra la migración (34/34, sin ediciones, sin
  referencias) y reemplazada dentro de la transacción.
- **Traslado:** una transacción, 39 s, 394 248 filas. `verificar` posterior: 12 tablas IDÉNTICAS (conteo y huella),
  4 vacías en ambos motores, sumas de control iguales, secuencias por encima del id máximo; huellas de MariaDB
  iguales antes y después (sin cambios).
- **Configuración Docker:** `APP_KEY` de Laragon copiada a `.env.docker` sin imprimirla; informes administrativos
  montados en solo lectura (44 293 filas Próstata, 21 794 Cérvix, igual que con el PHP de Windows).

### Comparación de exportaciones MariaDB frente a PostgreSQL

Mismo código, mismas entradas (4 JSON de La María con SHA-256 igual al registrado en las ejecuciones 373/374 y
los 2 JSON de la conversión 376 de Autoinmunes, con `valor_administrativo` 3 705 644 708), misma `APP_KEY`,
mismos informes. Cada motor ejecutó las rutas reales (`HttpKernel`) como administrador, con storage temporal,
sesión de base de datos en solo lectura, reconexión bloqueada y cada petición en una transacción revertida
(PostgreSQL quedó con 87 ejecuciones; MariaDB rechazó el insert final con el error 1792).

| Libro | Hojas | Filas | Sumas numéricas | Celdas distintas |
|-------|------:|------:|-----------------|------------------|
| Autoinmunes | 6 | iguales | iguales (RESUMEN 11 194 544 679,33) | 0 |
| La María Próstata | 15 | iguales | iguales en las 15 hojas | 3 (texto) |
| La María Cérvix | 15 | iguales | iguales en las 15 hojas | 2 (texto) |
| La María consolidado | 9 | iguales | iguales en las 9 hojas | 5 (texto) |

Todas las celdas distintas están en `PUENTE ECONOMICO`, columna `REGLA Y ALCANCE` (texto explicativo, sin
efecto en montos):

- **Identificador de estancia en el texto RN-16** (Próstata M792, Cérvix M738): cambia también entre dos corridas
  del mismo motor; fechas, tramos y bloques iguales. Inocuo.
- **CUM citado en "Sin tarifa contractual ni precio administrativo del CUM …"** (Próstata M716 `20056956-1` /
  `-7`, M727 y Cérvix M672 `36241-2` / `-6`): mismo IUM, distinto consecutivo por el orden de lectura (riesgo 4.1).
  Valor 0 en ambos motores.

### Incidente durante la comparación

El primer intento usó solo una sesión `READ ONLY`. Laravel trata el error de PostgreSQL
`SQLSTATE[25006]: Read only sql transaction: 7` como conexión perdida (`LostConnectionDetector`), reconectó y
reintentó el insert: se crearon 3 filas de prueba en `programa_ejecuciones` (ids 105–107). Se eliminaron tras
comprobar que eran exactamente esas, sin referencias, y se devolvió la secuencia a 104. La verificación posterior
dio todas las tablas IDÉNTICAS a MariaDB. Las comparaciones siguientes bloquearon la reconexión
(`setReconnector`) y no escribieron nada.
