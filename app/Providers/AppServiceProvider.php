<?php

namespace App\Providers;

use App\Programas\LaMaria\HistorialHospitalario;
use App\Programas\LaMaria\HistorialHospitalarioBaseDatos;
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
        //
    }
}
