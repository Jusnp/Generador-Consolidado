<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserIsActive
{
    /**
     * Cierra la sesión de un usuario desactivado aunque su sesión siga
     * vigente: desactivar en SIRUTA corta el acceso en la siguiente petición.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user !== null && ! $user->active) {
            Auth::logout();

            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()
                ->route('login')
                ->withErrors(['email' => 'Tu usuario está inactivo. Contacta al administrador.']);
        }

        return $next($request);
    }
}
