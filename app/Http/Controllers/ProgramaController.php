<?php

namespace App\Http\Controllers;

use App\Programas\ProgramaRegistry;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class ProgramaController extends Controller
{
    public function show(string $programa, ProgramaRegistry $programas): View|RedirectResponse
    {
        abort_unless($programas->exists($programa), 404);

        $definition = $programas->get($programa);

        if ($definition['hub']) {
            return view('programa-la-maria', [
                'programa' => $definition,
            ]);
        }

        if ($definition['habilitado'] && $definition['ruta'] !== null) {
            return redirect()->route($definition['ruta']);
        }

        return view('programa-pendiente', [
            'programa' => $definition,
        ]);
    }
}
