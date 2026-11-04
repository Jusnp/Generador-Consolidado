<?php

namespace App\Providers;

use App\Models\User;
use App\Programas\LaMaria\HistorialHospitalario;
use App\Programas\LaMaria\HistorialHospitalarioBaseDatos;
use App\Programas\ProgramaRegistry;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(HistorialHospitalario::class, HistorialHospitalarioBaseDatos::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Gate::define(
            'acceder-programa',
            fn (User $user, string $programa): bool => $this->app->make(ProgramaRegistry::class)->permiteAcceso($programa, $user)
        );
    }
}
