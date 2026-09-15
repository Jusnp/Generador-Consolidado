<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\JsonExcelController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\ActivityLogController;

Route::get('/login', [AuthController::class, 'showLogin'])
    ->name('login');

Route::post('/login', [AuthController::class, 'login'])
    ->name('login.authenticate');

Route::middleware('auth')->group(function () {

    /*
    |--------------------------------------------------------------------------
    | Dashboard
    |--------------------------------------------------------------------------
    */

    Route::get('/dashboard', function () {
        return view('dashboard');
    })->name('dashboard');


    /*
    |--------------------------------------------------------------------------
    | Cerrar sesión
    |--------------------------------------------------------------------------
    */

    Route::post('/logout', [AuthController::class, 'logout'])
        ->name('logout');


    /*
    |--------------------------------------------------------------------------
    | Generador de consolidado
    |--------------------------------------------------------------------------
    */

    Route::get('/json-excel', [JsonExcelController::class, 'index'])
        ->name('json-excel');

    Route::post('/json-excel/convert', [JsonExcelController::class, 'convert'])
        ->name('json-excel.convert');


    /*
    |--------------------------------------------------------------------------
    | Administración
    |--------------------------------------------------------------------------
    */

    Route::middleware('admin')->group(function () {

        /*
        | Gestión de usuarios
        */

        Route::resource('users', UserController::class);

        Route::patch(
            '/users/{user}/toggle-active',
            [UserController::class, 'toggleActive']
        )->name('users.toggle-active');


        /*
        | Registro de actividad
        */

        Route::get(
            '/activity-logs',
            [ActivityLogController::class, 'index']
        )->name('activity-logs.index');

        Route::get(
            '/activity-logs/{activityLog}',
            [ActivityLogController::class, 'show']
        )->name('activity-logs.show');

    });

});