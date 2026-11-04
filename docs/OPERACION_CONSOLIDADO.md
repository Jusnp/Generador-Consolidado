# Operación del consolidado JSON

## Carga mensual

En la pantalla de consolidado se seleccionan dos lotes explícitos: uno de
**Subsidiado** y otro de **Contributivo**. Cada lote admite entre 1 y 25 JSON.
El nombre del archivo no decide el régimen; por eso cada archivo debe cargarse
en el lote correcto.

El sistema hace una primera lectura en streaming de todos los JSON para contar
los duplicados de medicamentos por documento, código y mes. Después genera las
seis hojas del Excel sin acumular los registros completos en memoria.

## Reglas de medicamentos

La tabla `codigo_medicamentos` es la fuente para la homologación y la regla de
liquidación:

- `cums_homologo`: CUMS que se cruza con el catálogo NT.
- `tarifa_unitario`: respaldo cuando el catálogo NT no tiene tarifa.
- `divide_por_duplicados`: `1` es la regla normal; divide el valor entre las
  repeticiones del mismo documento, código y mes. `0` solo se usa si el área
  responsable aprueba una excepción para ese código.

Los registros sin catálogo permanecen identificados como `NO ENCONTRADO` en el
Excel. No se les inventa una tarifa ni una homologación desde el código.

La hoja `AM` conserva además `TIPO MEDICAMENTO RIPS` y
`VR DISPENSACION RIPS`, junto con `REGLA LIQUIDACION` y
`VALIDACION LIQUIDACION`. Cuando un medicamento está duplicado, el último
campo queda como `REQUIERE FUENTE DE CLASIFICACION`. Este aviso no modifica el
valor ni asigna una tarifa: exige que la regla de aplicación o dispensación
esté respaldada por una fuente autorizada antes del cierre de producción.

Cuando exista un catálogo autorizado con las columnas `Descripcion` y
`Servicio` (`DISPENSACION` o `APLICACIONES`), impórtelo así:

```powershell
php artisan catalogo:importar-clasificaciones-medicamentos C:\ruta\medicamentos.xlsx
```

Con una clasificación importada, el sistema divide las dispensaciones por sus
duplicados y liquida las aplicaciones sin dividir. La tarifa siempre sigue
viniendo del catálogo NT.

Para completar únicamente los campos faltantes del catálogo a partir de una
guía ya aprobada que contenga la hoja `AM`, use:

```powershell
php artisan catalogo:importar-desde-am C:\ruta\guia.xlsx
```

La importación conserva los datos de catálogo existentes cuando encuentra una
diferencia y la informa para revisión.

Después de aprobar formalmente la guía como nueva fuente del catálogo, se puede
aplicar sus diferencias de forma explícita con `--actualizar`.

## Ajustes aprobados de filas puntuales

Las anulaciones o valores diferentes se administran en
`medication_adjustments`, no en el controlador. Para importar un archivo CSV
aprobado use:

```powershell
php artisan catalogo:importar-ajustes-medicamentos C:\ruta\ajustes.csv
```

El CSV debe contener esta cabecera exacta:

```text
DOCUMENTO,REGIMEN,CODIGO,FECHA,OCURRENCIA,VALOR_TOTAL,MOTIVO
```

- `REGIMEN`: `SUBSIDIADA` o `CONTRIBUTIVO`.
- `FECHA`: `AAAA-MM-DD`.
- `OCURRENCIA`: empieza en `1` si hay varias filas iguales para el mismo
  documento, régimen, código y fecha.
- `VALOR_TOTAL`: puede ser `0` para una anulación.
- `MOTIVO`: justificación obligatoria y auditable.

## Tarifarios CUPS e insumos

La carga web del tarifario y los comandos `catalogo:importar-cups` y
`catalogo:importar-insumos-nt` aplican la misma regla
(`CatalogoConsolidadoImporter`):

- `codigo_cups` solo se alimenta de una hoja con código, **tipo de servicio**
  y tarifa. La hoja `INSUMOS` del mismo libro también trae código y tarifa,
  pero no clasifica el servicio; por eso nunca reemplaza una tarifa CUPS.
- La hoja `INSUMOS` solo alimenta `codigo_insumos_nt`. Un código repetido en
  la hoja queda como un único registro.
- Cada fila guarda en `hoja` la hoja de origen. Los códigos que no vienen en
  un archivo nuevo no se borran ni se desactivan.
- Una celda de tarifa CUPS vacía o no numérica no borra la tarifa vigente: se
  conservan la tarifa y su hoja de origen, y el código se informa al terminar
  la carga. Un código sin ninguna tarifa válida queda sin valor y también se
  informa; el sistema no asigna tarifas por su cuenta.
- Las fórmulas se leen por su valor calculado.

```powershell
php artisan catalogo:importar-cups C:\ruta\tarifario.xlsx --hoja=CUPS
php artisan catalogo:importar-insumos-nt C:\ruta\tarifario.xlsx --hoja=INSUMOS
```

Si la hoja indicada no tiene las columnas de su tarifario, el comando se
detiene sin modificar ninguna tarifa.

### Transporte T34004

El tarifario contractual registra dos valores para T34004:

| Hoja | Tabla | Clasificación | Valor |
|---|---|---|---|
| CUPS | `codigo_cups` | TRANSPORTE | $165.000 |
| INSUMOS | `codigo_insumos_nt` | NT Sí | $135.000 |

El valor aplicable es el de la hoja CUPS. En la hoja `OTROS`, T34004 se
liquida como Transporte (`ENCONTRADO TRANSPORTE CUPS`) por las unidades RIPS
(`cantidadOS` × $165.000) y no se suma además como insumo. Los $135.000 se
conservan en `codigo_insumos_nt` como referencia de la hoja INSUMOS. Cambiar
el valor aplicable exige un tarifario CUPS nuevo con respaldo contractual
aprobado.

## Despliegue

Antes de publicar una versión ejecute:

```powershell
php artisan migrate --force
php artisan test
```

Las migraciones reparan la estructura histórica de los catálogos NT y crean la
regla de división y la tabla de ajustes.
