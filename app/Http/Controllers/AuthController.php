<?php

namespace App\Http\Controllers;

use App\Services\ActivityLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class AuthController extends Controller
{
    public function showLogin()
    {
        return view('auth.login');
    }

    public function login(
        Request $request,
        ActivityLogger $activityLogger
    )
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ], [
            'email.required' => 'El correo electrónico es obligatorio.',
            'email.email' => 'Ingresa un correo electrónico válido.',
            'password.required' => 'La contraseña es obligatoria.',
        ]);

        $user = \App\Models\User::where(
            'email',
            $credentials['email']
        )->first();

        if (!$user) {
            return back()->withErrors([
                'email' => 'Las credenciales no son correctas.',
            ])->withInput($request->only('email'));
        }

        if (!$user->active) {
            return back()->withErrors([
                'email' => 'Tu usuario está inactivo. Contacta al administrador.',
            ])->withInput($request->only('email'));
        }

        if (!Auth::attempt([
            'email' => $credentials['email'],
            'password' => $credentials['password'],
        ])) {
            return back()->withErrors([
                'email' => 'Las credenciales no son correctas.',
            ])->withInput($request->only('email'));
        }

        $request->session()->regenerate();

        // Registrar inicio de sesión exitoso.
        $activityLogger->log(
            'login',
            'Usuario inició sesión correctamente.'
        );

        return redirect()->route('dashboard');
    }

    public function logout(
        Request $request,
        ActivityLogger $activityLogger
    )
    {
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