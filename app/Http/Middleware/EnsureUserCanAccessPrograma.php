<?php

namespace App\Http\Middleware;

use App\Programas\ProgramaRegistry;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Exige que el rol del usuario tenga acceso al programa: el indicado como parámetro
 * (programa:la-maria) o, sin él, el del segmento {programa} de la ruta. Un programa
 * inexistente sigue respondiendo 404.
 */
class EnsureUserCanAccessPrograma
{
    public function __construct(private ProgramaRegistry $programas) {}

    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next, ?string $programa = null): Response
    {
        $programa ??= (string) $request->route('programa');

        abort_unless($this->programas->exists($programa), 404);
        abort_unless(
            $this->programas->permiteAcceso($programa, $request->user()),
            403,
            'No tienes acceso a este programa.'
        );

        return $next($request);
    }
}
