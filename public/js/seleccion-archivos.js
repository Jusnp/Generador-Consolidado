(function () {
    'use strict';

    function formatSize(bytes) {
        return (bytes / 1048576).toLocaleString('es-CO', { minimumFractionDigits: 1, maximumFractionDigits: 1 }) + ' MB';
    }

    function fileKey(file) {
        return file.name + '|' + file.size + '|' + file.lastModified;
    }

    function connect(container) {
        const input = container.querySelector('[data-file-selection-input]');
        const manifest = container.querySelector('[data-file-selection-manifest]');
        const list = container.querySelector('[data-file-selection-list]');
        const summary = container.querySelector('[data-file-selection-summary]');
        const error = container.querySelector('[data-file-selection-error]');
        const clear = container.querySelector('[data-file-selection-clear]');
        const form = container.closest('form');
        const submit = form ? form.querySelector('[type="submit"]') : null;
        const limit = Number(container.dataset.limit || 0);
        let selected = [];

        if (!input || !window.DataTransfer) {
            return;
        }

        function render() {
            const transfer = new DataTransfer();
            selected.forEach(function (file) {
                transfer.items.add(file);
            });
            input.files = transfer.files;
            manifest.value = selected.length === 0 ? '' : JSON.stringify(selected.map(function (file) {
                return { nombre: file.name, tamano: file.size };
            }));

            list.innerHTML = '';
            selected.forEach(function (file, index) {
                const item = document.createElement('li');
                const name = document.createElement('span');
                const remove = document.createElement('button');

                name.textContent = file.name + ' · ' + formatSize(file.size);
                remove.type = 'button';
                remove.className = 'file-selection-remove';
                remove.textContent = 'Quitar';
                remove.setAttribute('aria-label', 'Quitar ' + file.name);
                remove.addEventListener('click', function () {
                    selected.splice(index, 1);
                    render();
                });
                item.append(name, remove);
                list.appendChild(item);
            });

            const total = selected.reduce(function (sum, file) {
                return sum + file.size;
            }, 0);
            const names = selected.map(function (file) {
                return file.name;
            });
            const repeated = names.filter(function (name, index) {
                return names.indexOf(name) !== index;
            });
            const problems = [];

            if (limit > 0 && selected.length > limit) {
                problems.push('Seleccionaste ' + selected.length + ' archivos y el máximo por carga es ' + limit + '. Quita ' + (selected.length - limit) + ' o genera el reporte por partes.');
            }

            if (repeated.length > 0) {
                problems.push('Hay nombres repetidos: ' + repeated.join(', ') + '. Si el contenido es idéntico, se procesará una sola vez.');
            }

            summary.hidden = selected.length === 0;
            summary.textContent = selected.length + (selected.length === 1 ? ' archivo seleccionado' : ' archivos seleccionados') + ' · ' + formatSize(total);
            error.hidden = problems.length === 0;
            error.textContent = problems.join(' ');
            clear.hidden = selected.length === 0;

            if (submit) {
                submit.disabled = limit > 0 && selected.length > limit;
            }
        }

        input.addEventListener('change', function () {
            const known = selected.map(fileKey);

            Array.prototype.forEach.call(input.files, function (file) {
                if (known.indexOf(fileKey(file)) === -1) {
                    selected.push(file);
                    known.push(fileKey(file));
                }
            });

            selected.sort(function (a, b) {
                return a.name.localeCompare(b.name);
            });
            render();
        });

        clear.addEventListener('click', function () {
            selected = [];
            render();
        });
    }

    function initialize() {
        document.querySelectorAll('[data-file-selection]').forEach(connect);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initialize);
    } else {
        initialize();
    }
})();
