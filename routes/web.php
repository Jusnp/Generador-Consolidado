<?php

use App\Http\Controllers\ActivityLogController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\CatalogoReferenciaController;
use App\Http\Controllers\JsonExcelController;
use App\Http\Controllers\ProgramaController;
use App\Http\Controllers\SeguimientoRutaController;
use App\Http\Controllers\UserController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/login');

Route::get('/login', [AuthController::class, 'showLogin'])
    ->name('login');

/*
| El modo activo se decide en runtime con config('services.authentik.enabled').
| Las rutas existen en ambos modos; AuthController rechaza el camino incorrecto
| para impedir bypass cuando Authentik está habilitado.
*/
Route::post('/login', [AuthController::class, 'login'])
    ->name('login.authenticate');

Route::get('/auth/authentik/redirect', [AuthController::class, 'redirectToAuthentik'])
    ->name('authentik.redirect');

Route::get('/auth/authentik/callback', [AuthController::class, 'authentikCallback'])
    ->name('authentik.callback');

Route::middleware('auth')->group(function () {
    Route::get('/programas/{programa}', [ProgramaController::class, 'show'])
        ->name('programas.show');

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

    Route::get('/la-maria', [ProgramaController::class, 'show'])
        ->defaults('programa', 'la-maria')
        ->name('la-maria');

    Route::get('/la-maria/prostata', [SeguimientoRutaController::class, 'index'])
        ->defaults('ejecucion', 'prostata')
        ->name('la-maria.prostata');

    Route::post('/la-maria/prostata/export', [SeguimientoRutaController::class, 'export'])
        ->defaults('ejecucion', 'prostata')
        ->name('la-maria.prostata.export');

    Route::get('/la-maria/cervix', [SeguimientoRutaController::class, 'index'])
        ->defaults('ejecucion', 'cervix')
        ->name('la-maria.cervix');

    Route::post('/la-maria/cervix/export', [SeguimientoRutaController::class, 'export'])
        ->defaults('ejecucion', 'cervix')
        ->name('la-maria.cervix.export');

    Route::redirect('/seguimiento-rutas', '/la-maria');

    Route::middleware('admin')->group(function () {
        Route::get('/referencias-manuales', [CatalogoReferenciaController::class, 'manualIndex'])
            ->name('referencias-manuales.index');
        Route::post('/referencias-manuales', [CatalogoReferenciaController::class, 'storeManual'])
            ->name('referencias-manuales.store');
        Route::put('/referencias-manuales/{referenciaManual}', [CatalogoReferenciaController::class, 'updateManual'])
            ->name('referencias-manuales.update');
        Route::patch('/referencias-manuales/{referenciaManual}/toggle', [CatalogoReferenciaController::class, 'toggleManual'])
            ->name('referencias-manuales.toggle');
        Route::patch('/catalogos-referencia/{catalogoReferencia}/items/{item}', [CatalogoReferenciaController::class, 'updateItem'])
            ->name('catalogos-referencia.items.update');
        Route::post('/catalogos-referencia/{catalogoReferencia}/items', [CatalogoReferenciaController::class, 'storeItem'])
            ->name('catalogos-referencia.items.store');
        Route::patch('/catalogos-referencia/{catalogoReferencia}/items/{item}/toggle', [CatalogoReferenciaController::class, 'toggleItem'])
            ->name('catalogos-referencia.items.toggle');
        Route::get('/catalogos-referencia/{catalogoReferencia}', [CatalogoReferenciaController::class, 'show'])
            ->name('catalogos-referencia.show');
        Route::get('/catalogos-referencia', [CatalogoReferenciaController::class, 'index'])
            ->name('catalogos-referencia.index');
        Route::get('/catalogos-referencia/consolidado/{type}', [CatalogoReferenciaController::class, 'showConsolidated'])
            ->name('catalogos-referencia.consolidado.show');
        Route::patch('/catalogos-referencia/consolidado/{source}/{id}', [CatalogoReferenciaController::class, 'updateConsolidated'])
            ->name('catalogos-referencia.consolidado.update');
        Route::post('/catalogos-referencia/consolidado/rows', [CatalogoReferenciaController::class, 'storeConsolidatedRow'])
            ->name('catalogos-referencia.consolidado.store-row');
        Route::patch('/catalogos-referencia/consolidado/{source}/{id}/toggle', [CatalogoReferenciaController::class, 'toggleConsolidated'])
            ->name('catalogos-referencia.consolidado.toggle');
        Route::post('/catalogos-referencia/consolidado/{type}/toggle-file', [CatalogoReferenciaController::class, 'toggleConsolidatedFile'])
            ->name('catalogos-referencia.consolidado.toggle-file');
        Route::post('/catalogos-referencia/{catalogoReferencia}/toggle-file', [CatalogoReferenciaController::class, 'toggleTechnicalFile'])
            ->name('catalogos-referencia.toggle-file');
    });

    /*
    |--------------------------------------------------------------------------
    | Administración
    |--------------------------------------------------------------------------
    */

    Route::middleware('admin')->group(function () {

        /*
        | Catálogos de referencia para el seguimiento mensual
        */

        Route::get('/catalogos-referencia/{catalogoReferencia}/download', [CatalogoReferenciaController::class, 'download'])
            ->name('catalogos-referencia.download');

        Route::post(
            '/catalogos-referencia',
            [CatalogoReferenciaController::class, 'store']
        )->name('catalogos-referencia.store');

        Route::post(
            '/catalogos-referencia/consolidado',
            [CatalogoReferenciaController::class, 'storeConsolidatedCatalog']
        )->name('catalogos-referencia.consolidado.store');

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
