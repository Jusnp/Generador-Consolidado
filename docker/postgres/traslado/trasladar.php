<?php

/**
 * Traslado de los datos operativos de SIRUTA: MariaDB de Laragon (solo lectura) → PostgreSQL de Docker.
 *
 * Uso, desde la raíz del proyecto con los contenedores encendidos (PHP de Windows, porque MariaDB está en el host):
 *   php docker/postgres/traslado/trasladar.php verificar
 *   php docker/postgres/traslado/trasladar.php trasladar --autorizado
 *
 * - MariaDB solo se lee dentro de una transacción READ ONLY con instantánea consistente.
 * - El traslado es una única transacción en PostgreSQL: COPY por tabla, verificación de conteo y huella SHA-256
 *   de cada tabla contra MariaDB, ajuste de secuencias y COMMIT. Cualquier diferencia aborta sin dejar cambios.
 * - Conserva ids y relaciones; los decimales viajan como texto, nunca como float.
 * - Se niega si una tabla destino tiene filas distintas de las ya validadas como idénticas (no duplica ni sobrescribe).
 * - `verificar` sirve también después del traslado: compara huellas tabla por tabla.
 */

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

const PG_USUARIO = 'json_excel';
const PG_BASE = 'json_excel_dev';
const NULO = '\N';

/** Orden compatible con las claves foráneas. */
const TABLAS = [
    'contratos',
    'catalogo_referencias',
    'catalogo_referencia_items',
    'catalogo_consolidado_imports',
    'codigo_cups',
    'codigo_medicamentos',
    'codigo_medicamentos_nt',
    'codigo_insumos_nt',
    'medication_adjustments',
    'medication_service_classifications',
    'referencias_manuales',
    'programa_ejecuciones',
    'ah_antecedentes',
    'ah_estancias_abiertas',
    'activity_logs',
];

/** Tablas ya copiadas con autorización previa: solo se verifican, sin las columnas excluidas. */
const YA_TRASLADADAS = ['users' => ['password', 'remember_token']];

/**
 * Filas que la migración 2026_09_10_000004 crea en una base vacía. Sus códigos ya existen en MariaDB con otros ids,
 * así que se reemplazan por la tabla completa de MariaDB solo si siguen intactas (conteo, sin ediciones y misma huella).
 */
const SEMILLA_MIGRACION = ['codigo_medicamentos' => 34];

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    exit(main($argv));
}

/**
 * Ejecuta `verificar` o `trasladar` y devuelve el código de salida.
 *
 * @param  list<string>  $argv
 */
function main(array $argv): int
{
    $modo = $argv[1] ?? 'verificar';

    if (! in_array($modo, ['verificar', 'trasladar'], true)) {
        fwrite(STDERR, "Uso: php docker/postgres/traslado/trasladar.php verificar|trasladar [--autorizado]\n");

        return 2;
    }

    if ($modo === 'trasladar' && ! in_array('--autorizado', $argv, true)) {
        fwrite(STDERR, "El traslado exige --autorizado.\n");

        return 2;
    }

    $raiz = dirname(__DIR__, 3);
    chdir($raiz);
    require $raiz.'/vendor/autoload.php';
    $app = require $raiz.'/bootstrap/app.php';
    $app->make(Kernel::class)->bootstrap();

    $mysql = DB::connection('mysql');

    if ($mysql->getDriverName() !== 'mysql' || $mysql->getDatabaseName() !== 'json_excel') {
        fwrite(STDERR, "El origen no es la base MariaDB json_excel.\n");

        return 1;
    }

    $pdo = $mysql->getPdo();
    $pdo->exec('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
    $pdo->exec('START TRANSACTION WITH CONSISTENT SNAPSHOT, READ ONLY');
    $pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, false);

    $esquemaPg = esquemaPostgres();
    $columnasMysql = columnasMysql($mysql);
    $conteosPg = conteosPostgres(array_merge(TABLAS, array_keys(YA_TRASLADADAS)));
    $bloqueos = [];
    $plan = [];

    foreach (array_merge(array_keys(YA_TRASLADADAS), TABLAS) as $tabla) {
        $columnas = columnasDeTabla($tabla, $esquemaPg, $columnasMysql, YA_TRASLADADAS[$tabla] ?? [], $bloqueos);
        $plan[$tabla] = ['columnas' => $columnas, 'pg' => $conteosPg[$tabla] ?? 0];
    }

    $idsPadre = [];
    $resumen = [];

    foreach ($plan as $tabla => $info) {
        if ($info['columnas'] === []) {
            continue;
        }

        $huellaPg = $info['pg'] > 0 ? huellaPostgres($tabla, $info['columnas']) : null;
        $semilla = semillaIntacta($tabla, $info['pg'], $bloqueos);
        $presentesPg = $info['pg'] > 0 && ! $semilla ? huellasPorIdPostgres($tabla, $info['columnas']) : [];
        $analisis = analizarTabla($pdo, $tabla, $info['columnas'], $esquemaPg, $presentesPg, $idsPadre, $bloqueos);

        if ($semilla && $huellaPg === $analisis['huella']) {
            $semilla = false;
            $analisis['copiar'] = 0;
        }

        $resumen[$tabla] = $analisis + ['pg' => $info['pg'], 'huella_pg' => $huellaPg, 'semilla' => $semilla];
    }

    $pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, true);
    $pdo->exec('ROLLBACK');

    imprimirResumen($resumen, $bloqueos);

    if ($bloqueos !== []) {
        return 1;
    }

    if ($modo === 'verificar') {
        return 0;
    }

    return trasladar($mysql, $plan, $resumen, $esquemaPg) ? 0 : 1;
}

/**
 * @return array<string, array<string, array{udt: string, p: ?int, s: ?int, l: ?int, n: string, def: ?string}>>
 */
function esquemaPostgres(): array
{
    $json = psql("SELECT json_agg(json_build_object('t', table_name, 'c', column_name, 'udt', udt_name, 'p', numeric_precision, 's', numeric_scale, 'l', character_maximum_length, 'n', is_nullable, 'def', column_default) ORDER BY table_name, ordinal_position) FROM information_schema.columns WHERE table_schema = 'public'");
    $esquema = [];

    foreach (json_decode($json, true) as $c) {
        $esquema[$c['t']][$c['c']] = $c;
    }

    return $esquema;
}

/**
 * @return array<string, list<string>>
 */
function columnasMysql($mysql): array
{
    $columnas = [];

    foreach ($mysql->select('SELECT table_name AS t, column_name AS c FROM information_schema.columns WHERE table_schema = DATABASE() ORDER BY table_name, ordinal_position') as $fila) {
        $columnas[$fila->t][] = $fila->c;
    }

    return $columnas;
}

/**
 * @param  list<string>  $tablas
 * @return array<string, int>
 */
function conteosPostgres(array $tablas): array
{
    $partes = array_map(fn (string $t): string => "SELECT '{$t}' AS t, count(*) AS n FROM \"{$t}\"", $tablas);

    return array_map('intval', json_decode(psql('SELECT json_object_agg(t, n) FROM ('.implode(' UNION ALL ', $partes).') x'), true));
}

/**
 * @param  list<string>  $excluidas
 * @param  list<string>  $bloqueos
 * @return list<array{c: string, udt: string, p: ?int, s: ?int, l: ?int, n: string, def: ?string}>
 */
function columnasDeTabla(string $tabla, array $esquemaPg, array $columnasMysql, array $excluidas, array &$bloqueos): array
{
    if (! isset($esquemaPg[$tabla])) {
        $bloqueos[] = "{$tabla}: no existe en PostgreSQL";

        return [];
    }

    if (! isset($columnasMysql[$tabla])) {
        $bloqueos[] = "{$tabla}: no existe en MariaDB";

        return [];
    }

    foreach (array_diff($columnasMysql[$tabla], array_keys($esquemaPg[$tabla])) as $soloMysql) {
        $bloqueos[] = "{$tabla}.{$soloMysql}: existe en MariaDB pero no en PostgreSQL (se perdería)";
    }

    $columnas = [];

    foreach ($esquemaPg[$tabla] as $nombre => $c) {
        if (in_array($nombre, $excluidas, true)) {
            continue;
        }

        if (! in_array($nombre, $columnasMysql[$tabla], true)) {
            if ($c['n'] === 'NO' && $c['def'] === null) {
                $bloqueos[] = "{$tabla}.{$nombre}: obligatoria en PostgreSQL y ausente en MariaDB";
            }

            continue;
        }

        $columnas[] = $c;
    }

    return $columnas;
}

/**
 * Lee la tabla completa de MariaDB, valida cada valor contra el tipo destino y calcula la huella.
 *
 * @param  array<int, string>  $presentesPg  id => huella de fila ya existente en PostgreSQL
 * @param  array<string, array<int, true>>  $idsPadre
 * @param  list<string>  $bloqueos
 * @return array{mysql: int, huella: string, copiar: int, sumas: array<string, string>}
 */
function analizarTabla(PDO $pdo, string $tabla, array $columnas, array $esquemaPg, array $presentesPg, array &$idsPadre, array &$bloqueos, $alCopiar = null): array
{
    $claves = clavesForaneas()[$tabla] ?? [];
    $unicas = clavesUnicas()[$tabla] ?? [];
    $vistos = [];
    $sumas = [];
    $hash = hash_init('sha256');
    $filas = 0;
    $copiar = 0;
    $presentesVistos = 0;

    $sql = 'SELECT '.implode(', ', array_map(fn (array $c): string => '`'.$c['c'].'`', $columnas))." FROM `{$tabla}` ORDER BY `id`";

    foreach ($pdo->query($sql, PDO::FETCH_ASSOC) as $fila) {
        $filas++;
        $canonicos = [];

        foreach ($columnas as $c) {
            $valor = $fila[$c['c']];
            $error = validar($valor, $c);

            if ($error !== null && count($bloqueos) < 200) {
                $bloqueos[] = "{$tabla}.{$c['c']} id={$fila['id']}: {$error}";
            }

            $canonicos[] = canonico($valor, $c);

            if ($c['udt'] === 'numeric' && $valor !== null) {
                $sumas[$c['c']] = bcadd($sumas[$c['c']] ?? '0', (string) $valor, (int) $c['s']);
            }
        }

        $huellaFila = hash('sha256', implode("\x1F", $canonicos));
        hash_update($hash, $huellaFila);
        $id = (int) $fila['id'];

        if (isset($presentesPg[$id])) {
            $presentesVistos++;

            if ($presentesPg[$id] !== $huellaFila) {
                $bloqueos[] = "{$tabla} id={$id}: ya existe en PostgreSQL con contenido distinto";
            }
        } else {
            $copiar++;

            if ($alCopiar !== null) {
                $alCopiar($fila, $columnas);
            }
        }

        foreach ($claves as $columna => $padre) {
            $valor = $fila[$columna];

            if ($valor !== null && ! isset($idsPadre[$padre][(int) $valor]) && $padre !== $tabla) {
                $bloqueos[] = "{$tabla}.{$columna} id={$id}: referencia a {$padre}.id={$valor} inexistente";
            }
        }

        foreach ($unicas as $indice => $cols) {
            $valores = array_map(fn (string $col) => $fila[$col], $cols);

            if (in_array(null, $valores, true)) {
                continue;
            }

            $clave = implode("\x1F", array_map('strval', $valores));

            if (isset($vistos[$indice][$clave])) {
                $bloqueos[] = "{$tabla} (".implode(', ', $cols).") duplicado en id={$id}";
            }

            $vistos[$indice][$clave] = true;
        }

        $idsPadre[$tabla][$id] = true;
    }

    if ($presentesVistos !== count($presentesPg)) {
        $bloqueos[] = "{$tabla}: PostgreSQL tiene filas que no existen en MariaDB";
    }

    return ['mysql' => $filas, 'huella' => $filas === 0 ? '-' : hash_final($hash), 'copiar' => $copiar, 'sumas' => $sumas];
}

/**
 * La tabla solo tiene las filas de la migración, nunca editadas: se pueden reemplazar por las de MariaDB.
 *
 * @param  list<string>  $bloqueos
 */
function semillaIntacta(string $tabla, int $filasPg, array &$bloqueos): bool
{
    if ($filasPg !== (SEMILLA_MIGRACION[$tabla] ?? null)) {
        return false;
    }

    $editadas = (int) psql("SELECT count(*) FROM \"{$tabla}\" WHERE updated_at IS DISTINCT FROM created_at");

    if ($editadas > 0) {
        $bloqueos[] = "{$tabla}: PostgreSQL tiene {$filasPg} filas ({$editadas} editadas); no coincide con la semilla de la migración";

        return false;
    }

    return true;
}

function validar(mixed $valor, array $c): ?string
{
    if ($valor === null) {
        return $c['n'] === 'NO' ? 'nulo en columna obligatoria' : null;
    }

    if (is_float($valor)) {
        return 'leído como float (riesgo de redondeo)';
    }

    $texto = (string) $valor;

    return match ($c['udt']) {
        'bool' => in_array($texto, ['0', '1'], true) ? null : "booleano inválido '{$texto}'",
        'int2' => (int) $texto >= -32768 && (int) $texto <= 32767 ? null : "{$texto} excede smallint",
        'int4' => (int) $texto >= -2147483648 && (int) $texto <= 2147483647 ? null : "{$texto} excede integer",
        'uuid' => preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $texto) ? null : 'uuid inválido',
        'timestamp' => preg_match('/^(?!0000)\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $texto) ? null : "fecha-hora inválida '{$texto}'",
        'date' => preg_match('/^(?!0000)\d{4}-\d{2}-\d{2}$/', $texto) ? null : "fecha inválida '{$texto}'",
        'json' => json_validate($texto) ? null : 'json inválido',
        'numeric' => decimalCabe($texto, (int) $c['p'], (int) $c['s']) ? null : "decimal {$texto} no cabe en numeric({$c['p']},{$c['s']})",
        'varchar', 'bpchar' => str_contains($texto, "\0") ? 'contiene byte nulo' : (mb_strlen($texto) > (int) $c['l'] ? 'texto de '.mb_strlen($texto)." caracteres excede {$c['l']}" : null),
        'text' => str_contains($texto, "\0") ? 'contiene byte nulo' : null,
        default => null,
    };
}

function decimalCabe(string $valor, int $precision, int $escala): bool
{
    if (! preg_match('/^-?(\d+)(?:\.(\d+))?$/', $valor, $m)) {
        return false;
    }

    $entera = ltrim($m[1], '0');
    $fraccion = rtrim($m[2] ?? '', '0');

    return strlen($entera) <= $precision - $escala && strlen($fraccion) <= $escala;
}

/**
 * Forma canónica sin ambigüedad: `n` para nulo y `v<caracteres>:<valor>` para el resto.
 */
function canonico(mixed $valor, array $c): string
{
    if ($valor === null) {
        return 'n';
    }

    $texto = match ($c['udt']) {
        'bool' => ((int) $valor) === 1 ? '1' : '0',
        'int2', 'int4', 'int8' => (string) (int) $valor,
        'numeric' => decimalCanonico((string) $valor),
        'uuid' => strtolower((string) $valor),
        default => (string) $valor,
    };

    return 'v'.mb_strlen($texto, 'UTF-8').':'.$texto;
}

function decimalCanonico(string $valor): string
{
    if (str_contains($valor, '.')) {
        $valor = rtrim(rtrim($valor, '0'), '.');
    }

    return $valor === '-0' ? '0' : $valor;
}

function expresionPg(array $c): string
{
    $columna = '"'.$c['c'].'"';
    $expresion = match ($c['udt']) {
        'bool' => "{$columna}::int::text",
        'numeric' => "trim_scale({$columna})::text",
        'timestamp' => "to_char({$columna}, 'YYYY-MM-DD HH24:MI:SS')",
        'date' => "to_char({$columna}, 'YYYY-MM-DD')",
        default => "{$columna}::text",
    };

    return "coalesce('v' || length({$expresion}) || ':' || {$expresion}, 'n')";
}

function filaPg(array $columnas): string
{
    return 'encode(sha256(convert_to(concat_ws(chr(31), '.implode(', ', array_map('expresionPg', $columnas))."), 'UTF8')), 'hex')";
}

function huellaSql(string $tabla, array $columnas): string
{
    return "SELECT count(*) || ' ' || coalesce(encode(sha256(convert_to(string_agg(".filaPg($columnas).", '' ORDER BY id), 'UTF8')), 'hex'), '-') FROM \"{$tabla}\"";
}

function huellaPostgres(string $tabla, array $columnas): string
{
    return explode(' ', psql(huellaSql($tabla, $columnas)))[1];
}

/**
 * @return array<int, string>
 */
function huellasPorIdPostgres(string $tabla, array $columnas): array
{
    $json = psql("SELECT coalesce(json_object_agg(id, h), '{}') FROM (SELECT id, ".filaPg($columnas)." AS h FROM \"{$tabla}\") x");

    return json_decode($json, true);
}

/**
 * @return array<string, array<string, string>> tabla => columna => tabla padre
 */
function clavesForaneas(): array
{
    static $claves = null;

    if ($claves === null) {
        $claves = [];
        $json = psql("SELECT coalesce(json_agg(json_build_object('t', tc.table_name, 'c', kcu.column_name, 'p', ccu.table_name)), '[]') FROM information_schema.table_constraints tc JOIN information_schema.key_column_usage kcu ON kcu.constraint_name = tc.constraint_name JOIN information_schema.constraint_column_usage ccu ON ccu.constraint_name = tc.constraint_name WHERE tc.constraint_type = 'FOREIGN KEY' AND tc.table_schema = 'public'");

        foreach (json_decode($json, true) as $fk) {
            $claves[$fk['t']][$fk['c']] = $fk['p'];
        }
    }

    return $claves;
}

/**
 * @return array<string, array<string, list<string>>> tabla => índice => columnas
 */
function clavesUnicas(): array
{
    static $unicas = null;

    if ($unicas === null) {
        $unicas = [];
        $json = psql("SELECT coalesce(json_agg(json_build_object('t', t.relname, 'i', i.relname, 'cols', (SELECT json_agg(a.attname ORDER BY k.ord) FROM unnest(ix.indkey) WITH ORDINALITY k(attnum, ord) JOIN pg_attribute a ON a.attrelid = t.oid AND a.attnum = k.attnum))), '[]') FROM pg_index ix JOIN pg_class t ON t.oid = ix.indrelid JOIN pg_class i ON i.oid = ix.indexrelid JOIN pg_namespace n ON n.oid = t.relnamespace WHERE n.nspname = 'public' AND ix.indisunique AND NOT ix.indisprimary");

        foreach (json_decode($json, true) as $u) {
            $unicas[$u['t']][$u['i']] = $u['cols'];
        }
    }

    return $unicas;
}

function imprimirResumen(array $resumen, array $bloqueos): void
{
    printf("%-36s %10s %10s %10s  %-14s %s\n", 'tabla', 'MariaDB', 'Postgres', 'a copiar', 'huella', 'estado');

    foreach ($resumen as $tabla => $r) {
        $estado = match (true) {
            $r['huella_pg'] !== null && $r['huella_pg'] === $r['huella'] => 'IDENTICA',
            $r['semilla'] ?? false => "pendiente: reemplaza {$r['pg']} filas de la migración",
            $r['copiar'] === 0 && $r['mysql'] === 0 => 'vacía en ambos',
            $r['pg'] === 0 => 'pendiente',
            default => 'parcial: '.($r['mysql'] - $r['copiar']).' filas ya validadas',
        };
        printf("%-36s %10d %10d %10d  %-14s %s\n", $tabla, $r['mysql'], $r['pg'], $r['copiar'], substr($r['huella'], 0, 12), $estado);
    }

    echo "\nSumas de control (MariaDB):\n";

    foreach ($resumen as $tabla => $r) {
        foreach ($r['sumas'] as $columna => $suma) {
            echo "  {$tabla}.{$columna} = {$suma}\n";
        }
    }

    echo $bloqueos === [] ? "\nSin bloqueos.\n" : "\nBLOQUEOS (".count($bloqueos)."):\n  ".implode("\n  ", $bloqueos)."\n";
}

function trasladar($mysql, array $plan, array $resumen, array $esquemaPg): bool
{
    $salida = tempnam(sys_get_temp_dir(), 'traslado');
    $proceso = proc_open(comandoPsql(), [0 => ['pipe', 'r'], 1 => ['file', $salida, 'w'], 2 => ['file', $salida, 'a']], $tuberias, getcwd());
    $entrada = $tuberias[0];
    $escribir = function (string $texto) use ($entrada): void {
        $escritos = fwrite($entrada, $texto);

        if ($escritos === false || $escritos < strlen($texto)) {
            throw new RuntimeException('PostgreSQL cerró la conexión durante el traslado.');
        }
    };

    $pdo = $mysql->getPdo();
    $pdo->exec('START TRANSACTION WITH CONSISTENT SNAPSHOT, READ ONLY');
    $pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, false);
    $esperado = [];
    $idsPadre = [];
    $marca = 'TRASLADO-CONFIRMADO-'.bin2hex(random_bytes(6));
    $enviado = false;

    try {
        $escribir("BEGIN;\n");

        foreach (TABLAS as $tabla) {
            $r = $resumen[$tabla];

            if ($r['semilla']) {
                $escribir('DO $g$ BEGIN IF ('.huellaSql($tabla, $plan[$tabla]['columnas']).") IS DISTINCT FROM '{$r['pg']} {$r['huella_pg']}' THEN RAISE EXCEPTION 'La semilla de {$tabla} cambió desde la verificación'; END IF; END \$g\$;\n");
                $escribir("DELETE FROM \"{$tabla}\";\n");

                continue;
            }

            $esperadas = $r['mysql'] - $r['copiar'];
            $escribir("DO \$g\$ BEGIN IF (SELECT count(*) FROM \"{$tabla}\") <> {$esperadas} THEN RAISE EXCEPTION 'La tabla {$tabla} cambió desde la verificación'; END IF; END \$g\$;\n");
        }

        foreach (array_merge(array_keys(YA_TRASLADADAS), TABLAS) as $tabla) {
            $columnas = $plan[$tabla]['columnas'];
            $presentes = $plan[$tabla]['pg'] > 0 && ! ($resumen[$tabla]['semilla'] ?? false) ? huellasPorIdPostgres($tabla, $columnas) : [];
            $abierto = false;
            $bloqueos = [];
            $alCopiar = function (array $fila, array $columnas) use ($escribir, $tabla, &$abierto): void {
                if (isset(YA_TRASLADADAS[$tabla])) {
                    throw new RuntimeException("{$tabla} tiene filas nuevas en MariaDB; no se copian en este procedimiento.");
                }

                if (! $abierto) {
                    $escribir("COPY \"{$tabla}\" (".implode(', ', array_map(fn (array $c): string => '"'.$c['c'].'"', $columnas)).") FROM STDIN;\n");
                    $abierto = true;
                }

                $escribir(implode("\t", array_map(fn (array $c): string => valorCopy($fila[$c['c']], $c), $columnas))."\n");
            };

            $analisis = analizarTabla($pdo, $tabla, $columnas, $esquemaPg, $presentes, $idsPadre, $bloqueos, $alCopiar);

            if ($abierto) {
                $escribir("\\.\n");
            }

            if ($bloqueos !== []) {
                throw new RuntimeException("{$tabla}: ".implode('; ', array_slice($bloqueos, 0, 5)));
            }

            $esperado[$tabla] = $analisis['mysql'].' '.$analisis['huella'];
            echo "{$tabla}: {$analisis['copiar']} filas enviadas\n";
        }

        foreach (TABLAS as $tabla) {
            $escribir('DO $v$ DECLARE r text; BEGIN '.huellaSql($tabla, $plan[$tabla]['columnas'])." INTO r; IF r IS DISTINCT FROM '{$esperado[$tabla]}' THEN RAISE EXCEPTION 'Huella distinta en {$tabla}: %', r; END IF; END \$v\$;\n");
            $escribir("SELECT setval(pg_get_serial_sequence('\"{$tabla}\"', 'id'), max(id)) FROM \"{$tabla}\" HAVING max(id) IS NOT NULL;\n");
        }

        $escribir("COMMIT;\nSELECT '{$marca}';\n");
        $enviado = true;
    } catch (Throwable $e) {
        echo 'Traslado detenido antes del COMMIT: '.$e->getMessage()."\n";
    } finally {
        $pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, true);
        $pdo->exec('ROLLBACK');
        fclose($entrada);
    }

    $codigo = proc_close($proceso);
    $mensajes = trim((string) file_get_contents($salida));
    unlink($salida);

    if (! $enviado || $codigo !== 0 || ! str_contains($mensajes, $marca)) {
        $errores = preg_grep('/ERROR|FATAL/', explode("\n", $mensajes));
        echo "TRASLADO REVERTIDO: PostgreSQL quedó como estaba.\n".preg_replace('/"[^"]*"|\'[^\']*\'/', '<valor>', implode("\n", $errores))."\n";

        return false;
    }

    echo "TRASLADO CONFIRMADO. Ejecutar de nuevo `verificar`: todas las tablas deben quedar IDENTICA.\n";

    return true;
}

function valorCopy(mixed $valor, array $c): string
{
    if ($valor === null) {
        return NULO;
    }

    $texto = match ($c['udt']) {
        'bool' => ((int) $valor) === 1 ? 't' : 'f',
        'uuid' => strtolower((string) $valor),
        default => (string) $valor,
    };

    return strtr($texto, ['\\' => '\\\\', "\t" => '\t', "\n" => '\n', "\r" => '\r']);
}

/**
 * @return list<string>
 */
function comandoPsql(): array
{
    return ['docker', 'compose', 'exec', '-T', 'postgres', 'psql', '-X', '-v', 'ON_ERROR_STOP=1', '-q', '-At', '-U', PG_USUARIO, '-d', PG_BASE];
}

function psql(string $sql): string
{
    $proceso = proc_open(comandoPsql(), [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $tuberias, getcwd());
    fwrite($tuberias[0], $sql.";\n");
    fclose($tuberias[0]);
    $salida = stream_get_contents($tuberias[1]);
    $error = stream_get_contents($tuberias[2]);
    fclose($tuberias[1]);
    fclose($tuberias[2]);

    if (proc_close($proceso) !== 0) {
        throw new RuntimeException('PostgreSQL: '.trim($error));
    }

    return trim($salida);
}
