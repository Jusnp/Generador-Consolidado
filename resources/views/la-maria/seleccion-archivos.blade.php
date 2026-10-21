<div class="file-selection" data-file-selection data-limit="{{ $limiteArchivos }}">
    <label for="{{ $inputId }}" class="form-label">
        Archivos RIPS JSON <span class="required">*</span>
    </label>

    <input
        id="{{ $inputId }}"
        name="archivos[]"
        type="file"
        accept=".json,.txt,application/json,text/plain"
        class="form-input"
        multiple
        required
        data-file-selection-input
    >
    <input type="hidden" name="archivos_declarados" value="" data-file-selection-manifest>

    <div class="form-help">
        Hasta {{ $limiteArchivos }} archivos por carga, de uno o varios meses, con un máximo de 500 MB por archivo.
        Si eliges archivos en varias tandas, se suman a la lista. Revisa la cantidad antes de generar: el servidor
        rechaza la carga si no recibe todos los archivos de esta lista.
    </div>

    <p class="file-selection-summary" data-file-selection-summary hidden></p>
    <p class="file-selection-error" data-file-selection-error role="alert" hidden></p>
    <ol class="file-selection-list" data-file-selection-list></ol>
    <button type="button" class="btn btn-secondary file-selection-clear" data-file-selection-clear hidden>Quitar todos</button>
</div>
