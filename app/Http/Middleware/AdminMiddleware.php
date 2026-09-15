<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AdminMiddleware
{
    /**
     * Handle an incoming request.
     */
    public function handle(
        Request $request,
        Closure $next
    ): Response {
        /*
         * Primero comprobamos que exista
         * un usuario autenticado.
         */
        if (!$request->user()) {
            return redirect()->route('login');
        }

        /*
         * Después comprobamos que el usuario
         * tenga permisos de administrador.
         */
        if ($request->user()->role !== 'admin') {
            abort(403, 'No tienes permisos para acceder a esta sección.');
        }

        /*
         * Si es administrador, permitimos
         * continuar con la petición.
         */
        return $next($request);
    }
}