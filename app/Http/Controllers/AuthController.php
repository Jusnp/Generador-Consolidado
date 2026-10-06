<?php

namespace App\Http\Controllers;

use App\Services\ActivityLogger;
use App\Services\AuthentikAuthenticator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\View\View;

class AuthController extends Controller
{
    public function showLogin(): View
    {
        return view('auth.login');
    }

    public function redirectToAuthentik(
        Request $request,
        AuthentikAuthenticator $authentikAuthenticator
    ): RedirectResponse {
        if (! $authentikAuthenticator->isConfigured()) {
            return redirect()
                ->route('login')
                ->withErrors(['email' => 'El acceso con Authentik aún no está configurado.']);
        }

        $state = Str::random(64);
        $codeVerifier = Str::random(96);

        $request->session()->put('authentik_oauth_state', $state);
        $request->session()->put('authentik_code_verifier', $codeVerifier);

        return redirect()->away($authentikAuthenticator->authorizationUrl($state, $codeVerifier));
    }

    public function authentikCallback(
        Request $request,
        AuthentikAuthenticator $authentikAuthenticator,
        ActivityLogger $activityLogger
    ): RedirectResponse {
        $state = $request->session()->pull('authentik_oauth_state');
        $codeVerifier = $request->session()->pull('authentik_code_verifier');
        $callbackState = $request->query('state');
        $code = $request->query('code');

        if (! is_string($state)
            || ! is_string($codeVerifier)
            || ! is_string($callbackState)
            || ! hash_equals($state, $callbackState)
            || ! is_string($code)
            || $code === '') {
            return redirect()
                ->route('login')
                ->withErrors(['email' => 'No fue posible validar la respuesta de Authentik. Intenta nuevamente.']);
        }

        try {
            $claims = $authentikAuthenticator->claimsForAuthorizationCode($code, $codeVerifier);
            $user = $authentikAuthenticator->activeUserForClaims($claims);
        } catch (\Throwable $exception) {
            report($exception);

            return redirect()
                ->route('login')
                ->withErrors(['email' => 'No fue posible iniciar sesión con Authentik. Intenta nuevamente.']);
        }

        if ($user === null) {
            return redirect()
                ->route('login')
                ->withErrors(['email' => 'Tu cuenta de Authentik no tiene un usuario activo autorizado en este sistema.']);
        }

        Auth::login($user);
        $request->session()->regenerate();

        $activityLogger->log(
            'login_authentik',
            'Usuario inició sesión correctamente mediante Authentik.'
        );

        return redirect()->route('dashboard');
    }

    public function logout(
        Request $request,
        ActivityLogger $activityLogger
    ): RedirectResponse {
        // Registrar la actividad antes de cerrar la sesión,
        // porque todavía necesitamos identificar al usuario.
        if ($request->user()) {
            $activityLogger->log(
                'logout',
                'Usuario cerró sesión.'
            );
        }

        Auth::logout();

        $request->session()->invalidate();

        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
