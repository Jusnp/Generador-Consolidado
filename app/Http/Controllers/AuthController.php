<?php

namespace App\Http\Controllers;

use App\Models\User;
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
        return view('auth.login', [
            'authentikEnabled' => (bool) config('services.authentik.enabled'),
        ]);
    }

    public function login(
        Request $request,
        ActivityLogger $activityLogger
    ): RedirectResponse {
        if (config('services.authentik.enabled')) {
            abort(404);
        }

        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ], [
            'email.required' => 'El correo electrónico es obligatorio.',
            'email.email' => 'Ingresa un correo electrónico válido.',
            'password.required' => 'La contraseña es obligatoria.',
        ]);

        $user = User::query()->where('email', $credentials['email'])->first();

        if (! $user) {
            return back()->withErrors([
                'email' => 'Las credenciales no son correctas.',
            ])->withInput($request->only('email'));
        }

        if (! $user->active) {
            return back()->withErrors([
                'email' => 'Tu usuario está inactivo. Contacta al administrador.',
            ])->withInput($request->only('email'));
        }

        if (! Auth::attempt([
            'email' => $credentials['email'],
            'password' => $credentials['password'],
        ])) {
            return back()->withErrors([
                'email' => 'Las credenciales no son correctas.',
            ])->withInput($request->only('email'));
        }

        $request->session()->regenerate();

        $activityLogger->log(
            'login',
            'Usuario inició sesión correctamente.'
        );

        return redirect()->route('dashboard');
    }

    public function redirectToAuthentik(
        Request $request,
        AuthentikAuthenticator $authentikAuthenticator
    ): RedirectResponse {
        if (! config('services.authentik.enabled')) {
            return redirect()
                ->route('login')
                ->withErrors(['email' => 'El acceso con Authentik no está habilitado.']);
        }

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
        if (! config('services.authentik.enabled')) {
            return redirect()
                ->route('login')
                ->withErrors(['email' => 'El acceso con Authentik no está habilitado.']);
        }

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
