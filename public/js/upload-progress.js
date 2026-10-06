(function () {
    'use strict';

    function uploadForms() {
        return document.querySelectorAll('[data-upload-progress-form]');
    }

    function setProgress(form, percentage, message) {
        const wrapper = form.querySelector('[data-upload-progress]');
        const bar = form.querySelector('[data-upload-progress-bar]');
        const status = form.querySelector('[data-upload-progress-status]');
        const value = form.querySelector('[data-upload-progress-value]');

        if (!wrapper || !bar || !status || !value) {
            return;
        }

        wrapper.hidden = false;
        bar.style.width = percentage + '%';
        status.textContent = message;
        value.textContent = percentage + '%';
        wrapper.setAttribute('aria-valuenow', String(percentage));
    }

    function setError(form, message) {
        const error = form.querySelector('[data-upload-progress-error]');

        if (!error) {
            return;
        }

        error.hidden = false;
        error.textContent = message;
    }

    function clearError(form) {
        const error = form.querySelector('[data-upload-progress-error]');

        if (!error) {
            return;
        }

        error.hidden = true;
        error.textContent = '';
    }

    function submitButton(form) {
        return form.querySelector('[data-upload-progress-submit]')
            || form.querySelector('button[type="submit"]');
    }

    function filenameFromResponse(xhr) {
        const disposition = xhr.getResponseHeader('content-disposition') || '';
        const utf8Match = disposition.match(/filename\*=UTF-8''([^;]+)/i);
        const regularMatch = disposition.match(/filename="?([^";]+)"?/i);

        if (utf8Match) {
            return decodeURIComponent(utf8Match[1]);
        }

        return regularMatch ? regularMatch[1] : 'reporte.xlsx';
    }

    async function responseMessage(xhr) {
        let body = xhr.response;

        if (body instanceof Blob) {
            body = await body.text();
        }

        if (typeof body !== 'string' || body === '') {
            return 'No se pudo completar la carga. Intenta nuevamente.';
        }

        try {
            const data = JSON.parse(body);
            const firstError = data.errors ? Object.values(data.errors).flat()[0] : null;

            return firstError || data.message || 'No se pudo completar la carga. Intenta nuevamente.';
        } catch (error) {
            return 'No se pudo completar la carga. Verifica el archivo e intenta nuevamente.';
        }
    }

    function downloadResponse(xhr) {
        const url = URL.createObjectURL(xhr.response);
        const link = document.createElement('a');

        link.href = url;
        link.download = filenameFromResponse(xhr);
        document.body.appendChild(link);
        link.click();
        link.remove();
        window.setTimeout(function () {
            URL.revokeObjectURL(url);
        }, 1000);
    }

    function connectForm(form) {
        if (form.dataset.uploadProgressConnected === 'true') {
            return;
        }

        form.dataset.uploadProgressConnected = 'true';

        form.addEventListener('submit', function (event) {
            if (!window.XMLHttpRequest || !window.FormData) {
                return;
            }

            event.preventDefault();

            const button = submitButton(form);
            const mode = form.dataset.uploadProgressMode || 'redirect';
            const request = new XMLHttpRequest();

            clearError(form);
            setProgress(form, 0, 'Preparando archivos…');

            if (button) {
                button.disabled = true;
            }

            request.open(form.method || 'POST', form.action);
            request.setRequestHeader('Accept', 'application/json');
            request.responseType = mode === 'download' ? 'blob' : 'text';
            request.timeout = 0;

            request.upload.addEventListener('progress', function (progress) {
                if (!progress.lengthComputable) {
                    setProgress(form, 50, 'Subiendo archivos…');

                    return;
                }

                const percentage = Math.min(90, Math.round((progress.loaded / progress.total) * 90));
                setProgress(form, percentage, 'Subiendo archivos…');
            });

            request.upload.addEventListener('load', function () {
                setProgress(form, 92, 'Procesando información…');
            });

            request.addEventListener('load', async function () {
                const contentType = request.getResponseHeader('content-type') || '';
                const isSpreadsheet = contentType.includes('spreadsheetml')
                    || contentType.includes('application/vnd.ms-excel');

                if (request.status >= 200 && request.status < 300) {
                    if (mode === 'download' && isSpreadsheet) {
                        setProgress(form, 100, 'Excel generado. Iniciando descarga…');
                        downloadResponse(request);
                    } else if (mode === 'redirect') {
                        setProgress(form, 100, 'Catálogo actualizado. Recargando…');
                        window.setTimeout(function () {
                            window.location.reload();
                        }, 600);

                        return;
                    } else {
                        setProgress(form, 100, 'Proceso completado.');
                    }
                } else {
                    setError(form, await responseMessage(request));
                    setProgress(form, 0, 'La carga no se pudo completar.');
                }

                if (button) {
                    button.disabled = false;
                }
            });

            request.addEventListener('error', function () {
                setError(form, 'Se perdió la conexión durante la carga. Intenta nuevamente.');
                setProgress(form, 0, 'La carga no se pudo completar.');

                if (button) {
                    button.disabled = false;
                }
            });

            request.send(new FormData(form));
        });
    }

    function initialize() {
        uploadForms().forEach(connectForm);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initialize);
    } else {
        initialize();
    }
})();
