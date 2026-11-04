<?php

/**
 * Compara dos libros Excel de SIRUTA (original de Laragon y réplica generada en Docker) celda por celda.
 * No imprime el contenido de las celdas: solo hojas, posiciones con diferencias y sumas numéricas por hoja.
 *
 * Uso: php docker/postgres/traslado/comparar_excel.php ORIGINAL.xlsx REPLICA.xlsx [--estricto] [--detalle]
 *
 * Sin --estricto se ignoran los seudónimos `LM-…`, que dependen de APP_KEY y no del cálculo.
 * Con --detalle lista cada celda distinta con el encabezado de su columna; muestra los valores solo si son
 * numéricos (con su diferencia) y, si son texto, solo indica que difieren.
 */

declare(strict_types=1);

use OpenSpout\Reader\XLSX\Reader;

require dirname(__DIR__, 3).'/vendor/autoload.php';

[$original, $replica] = [$argv[1] ?? '', $argv[2] ?? ''];
$estricto = in_array('--estricto', $argv, true);
$detalle = in_array('--detalle', $argv, true);

if (! is_file($original) || ! is_file($replica)) {
    fwrite(STDERR, "Uso: php docker/postgres/traslado/comparar_excel.php ORIGINAL.xlsx REPLICA.xlsx [--estricto]\n");
    exit(2);
}

/**
 * @return array<string, list<list<mixed>>>
 */
function leerLibro(string $ruta): array
{
    $reader = new Reader;
    $reader->open($ruta);
    $hojas = [];

    foreach ($reader->getSheetIterator() as $hoja) {
        $filas = [];

        foreach ($hoja->getRowIterator() as $fila) {
            $filas[] = $fila->toArray();
        }

        $hojas[$hoja->getName()] = $filas;
    }

    $reader->close();

    return $hojas;
}

function normalizar(mixed $valor, bool $estricto): string
{
    if ($valor instanceof DateTimeInterface) {
        return $valor->format('Y-m-d H:i:s');
    }

    $texto = is_float($valor) ? rtrim(rtrim(sprintf('%.10F', $valor), '0'), '.') : (string) $valor;

    return ! $estricto && preg_match('/^LM-[0-9a-f]{20}$/', $texto) ? 'LM-<seudonimo>' : $texto;
}

function sumaNumerica(array $filas): string
{
    $suma = '0';

    foreach ($filas as $fila) {
        foreach ($fila as $valor) {
            if (is_int($valor) || is_float($valor)) {
                $suma = bcadd($suma, sprintf('%.6F', $valor), 6);
            }
        }
    }

    return $suma;
}

function columna(int $indice): string
{
    $letras = '';

    for ($n = $indice + 1; $n > 0; $n = intdiv($n - 1, 26)) {
        $letras = chr(65 + ($n - 1) % 26).$letras;
    }

    return $letras;
}

$a = leerLibro($original);
$b = leerLibro($replica);
$totalDiferencias = 0;
$detalles = [];

$soloOriginal = array_diff(array_keys($a), array_keys($b));
$soloReplica = array_diff(array_keys($b), array_keys($a));

if ($soloOriginal || $soloReplica) {
    echo 'Hojas solo en original: ', implode(', ', $soloOriginal) ?: '-', ' | solo en réplica: ', implode(', ', $soloReplica) ?: '-', "\n";
    $totalDiferencias += count($soloOriginal) + count($soloReplica);
}

printf("%-28s %8s %8s %10s  %22s %22s\n", 'hoja', 'filas A', 'filas B', 'celdas ≠', 'suma numérica A', 'suma numérica B');

foreach (array_intersect(array_keys($a), array_keys($b)) as $hoja) {
    $diferentes = [];
    $maxFilas = max(count($a[$hoja]), count($b[$hoja]));

    for ($f = 0; $f < $maxFilas; $f++) {
        $filaA = $a[$hoja][$f] ?? [];
        $filaB = $b[$hoja][$f] ?? [];
        $maxCols = max(count($filaA), count($filaB));

        for ($c = 0; $c < $maxCols; $c++) {
            if (normalizar($filaA[$c] ?? '', $estricto) !== normalizar($filaB[$c] ?? '', $estricto)) {
                $diferentes[] = columna($c).($f + 1);

                if ($detalle && count($diferentes) <= 40) {
                    $va = $filaA[$c] ?? '';
                    $vb = $filaB[$c] ?? '';
                    $encabezado = mb_substr((string) normalizar($a[$hoja][0][$c] ?? '', true), 0, 40);
                    $valores = (is_int($va) || is_float($va)) && (is_int($vb) || is_float($vb))
                        ? sprintf('A=%s B=%s diferencia=%s', normalizar($va, true), normalizar($vb, true), bcsub(sprintf('%.6F', $vb), sprintf('%.6F', $va), 6))
                        : sprintf('texto distinto (largo A=%d, B=%d)', mb_strlen(normalizar($va, true)), mb_strlen(normalizar($vb, true)));
                    $detalles[] = sprintf('    %s!%s [%s] %s', $hoja, columna($c).($f + 1), $encabezado, $valores);
                }
            }
        }
    }

    $totalDiferencias += count($diferentes);
    printf("%-28s %8d %8d %10d  %22s %22s\n", mb_substr($hoja, 0, 28), count($a[$hoja]), count($b[$hoja]), count($diferentes), sumaNumerica($a[$hoja]), sumaNumerica($b[$hoja]));

    if ($diferentes !== []) {
        echo '    primeras celdas distintas: ', implode(', ', array_slice($diferentes, 0, 15)), "\n";
    }
}

if ($detalles !== []) {
    echo "\nDetalle (máximo 40 por hoja):\n".implode("\n", $detalles)."\n";
}

echo $totalDiferencias === 0 ? "\nLIBROS EQUIVALENTES\n" : "\nDIFERENCIAS: {$totalDiferencias}\n";
exit($totalDiferencias === 0 ? 0 : 1);
