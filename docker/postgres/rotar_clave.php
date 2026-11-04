<?php

// Rota la clave del rol PostgreSQL de Docker sin imprimirla ni pasarla por argumentos de procesos.
// Uso (PowerShell, desde la raíz del proyecto): php docker/postgres/rotar_clave.php .
// Ejecutarlo desde PowerShell: la herramienta Bash de Claude Code guarda en su transcripción el diff de los archivos que modifica.
// 1) escribe la clave nueva en docker/secrets/postgres_password.txt (copia de recuperación),
// 2) envía a PostgreSQL solo el verificador SCRAM-SHA-256 por stdin,
// 3) actualiza DB_PASSWORD en .env.docker,
// 4) comprueba que la clave nueva entra y la anterior es rechazada.

$project = $argv[1];
chdir($project);

function run(array $command, string $stdin = ''): array
{
    $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, getcwd());
    fwrite($pipes[0], $stdin);
    fclose($pipes[0]);
    $out = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    return [proc_close($process), trim($out), trim($err)];
}

function envValue(string $file, string $key): string
{
    foreach (file($file, FILE_IGNORE_NEW_LINES) as $line) {
        if (str_starts_with($line, $key.'=')) {
            return trim(substr($line, strlen($key) + 1), '"');
        }
    }

    throw new RuntimeException("Falta {$key} en {$file}");
}

function scramVerifier(string $password): string
{
    $salt = random_bytes(16);
    $iterations = 4096;
    $salted = hash_pbkdf2('sha256', $password, $salt, $iterations, 32, true);
    $storedKey = hash('sha256', hash_hmac('sha256', 'Client Key', $salted, true), true);
    $serverKey = hash_hmac('sha256', 'Server Key', $salted, true);

    return 'SCRAM-SHA-256$'.$iterations.':'.base64_encode($salt).'$'.base64_encode($storedKey).':'.base64_encode($serverKey);
}

$probe = <<<'PHP'
$p = trim(fgets(STDIN));
try { new PDO('pgsql:host=postgres;port=5432;dbname=' . $argv[1], $argv[2], $p); echo 'ACEPTADA'; }
catch (PDOException $e) { echo str_contains($e->getMessage(), 'password authentication failed') ? 'RECHAZADA' : 'ERROR ' . $e->getCode(); }
PHP;

$secretFile = 'docker/secrets/postgres_password.txt';
$user = envValue('.env.docker', 'DB_USERNAME');
$database = envValue('.env.docker', 'DB_DATABASE');
$oldPassword = envValue('.env.docker', 'DB_PASSWORD');

if (! preg_match('/^[a-z_][a-z0-9_]*$/', $user) || ! preg_match('/^[a-z_][a-z0-9_]*$/', $database)) {
    throw new RuntimeException('Usuario o base con caracteres inesperados.');
}

$previousSecret = file_exists($secretFile) ? file_get_contents($secretFile) : null;

[$code, $result] = run(['docker', 'compose', 'exec', '-T', 'app', 'php', '-r', $probe, $database, $user], $oldPassword."\n");
echo "clave anterior antes de rotar: {$result}\n";
if ($result !== 'ACEPTADA') {
    exit(1);
}

$newPassword = bin2hex(random_bytes(24));

if (! is_dir('docker/secrets')) {
    mkdir('docker/secrets', 0700, true);
}
file_put_contents($secretFile, $newPassword);
echo "copia de recuperacion escrita en {$secretFile}\n";

[$code, , $err] = run(
    ['docker', 'compose', 'exec', '-T', 'postgres', 'psql', '-v', 'ON_ERROR_STOP=1', '-q', '-U', $user, '-d', $database],
    "ALTER ROLE \"{$user}\" PASSWORD '".scramVerifier($newPassword)."';\n"
);
if ($code !== 0) {
    $previousSecret === null ? unlink($secretFile) : file_put_contents($secretFile, $previousSecret);
    fwrite(STDERR, "ALTER ROLE fallo; la clave anterior sigue vigente.\n");
    exit(1);
}
echo "ALTER ROLE aplicado (verificador SCRAM-SHA-256)\n";

$env = file_get_contents('.env.docker');
$updated = preg_replace('/^DB_PASSWORD=.*$/m', 'DB_PASSWORD='.$newPassword, $env, -1, $replacements);
if ($replacements !== 1 || file_put_contents('.env.docker', $updated) === false) {
    fwrite(STDERR, "No se pudo actualizar .env.docker; la clave nueva esta en {$secretFile}.\n");
    exit(1);
}
echo ".env.docker actualizado (DB_PASSWORD)\n";

[, $new] = run(['docker', 'compose', 'exec', '-T', 'app', 'php', '-r', $probe, $database, $user], $newPassword."\n");
[, $old] = run(['docker', 'compose', 'exec', '-T', 'app', 'php', '-r', $probe, $database, $user], $oldPassword."\n");
echo "clave nueva: {$new}\nclave anterior: {$old}\n";

exit($new === 'ACEPTADA' && $old === 'RECHAZADA' ? 0 : 1);
