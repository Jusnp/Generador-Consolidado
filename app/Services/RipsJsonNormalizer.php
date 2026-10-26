<?php

namespace App\Services;

use JsonMachine\Exception\PathNotFoundException;
use JsonMachine\Items;
use JsonMachine\JsonDecoder\ExtJsonDecoder;
use RuntimeException;

class RipsJsonNormalizer
{
    /**
     * @return array{pointer: string, usuarios: int, servicios: array<string, int>}
     */
    public function inspect(string $path): array
    {
        $pointer = $this->detectPointer($path);
        $usuarios = 0;
        $servicios = array_fill_keys(['consultas', 'procedimientos', 'medicamentos', 'otrosServicios'], 0);

        try {
            foreach ($this->users($path, $pointer) as $index => $usuario) {
                if (! is_int($index) || ! is_array($usuario)
                    || trim((string) ($usuario['numDocumentoIdentificacion'] ?? '')) === ''
                    || ! isset($usuario['servicios']) || ! is_array($usuario['servicios'])) {
                    throw new RuntimeException('El arreglo de usuarios contiene un registro RIPS incompleto.');
                }

                $usuarios++;

                foreach ($usuario['servicios'] as $tipo => $lista) {
                    if (! array_key_exists($tipo, $servicios)) {
                        throw new RuntimeException('El archivo contiene un tipo de servicio RIPS no admitido.');
                    }

                    if (! is_array($lista) || ! array_is_list($lista)) {
                        throw new RuntimeException('El archivo contiene una lista de servicios RIPS inválida.');
                    }

                    foreach ($lista as $servicio) {
                        if (! is_array($servicio)) {
                            throw new RuntimeException('El archivo contiene una prestación RIPS inválida.');
                        }
                    }

                    $servicios[$tipo] += count($lista);
                }
            }
        } catch (RuntimeException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            throw new RuntimeException('El JSON RIPS está incompleto o malformado.', previous: $exception);
        }

        if ($usuarios === 0) {
            throw new RuntimeException('El archivo RIPS no contiene usuarios.');
        }

        return compact('pointer', 'usuarios', 'servicios');
    }

    public function users(string $path, string $pointer): Items
    {
        return Items::fromFile($path, [
            'pointer' => $pointer,
            'decoder' => new ExtJsonDecoder(true),
        ]);
    }

    private function detectPointer(string $path): string
    {
        foreach (['/usuarios', '/rips/usuarios'] as $pointer) {
            try {
                foreach ($this->users($path, $pointer) as $usuario) {
                    return $pointer;
                }
            } catch (PathNotFoundException) {
                continue;
            } catch (\Throwable $exception) {
                throw new RuntimeException('El JSON RIPS está incompleto o malformado.', previous: $exception);
            }
        }

        throw new RuntimeException('El archivo no contiene una estructura RIPS válida con un arreglo de usuarios.');
    }
}
