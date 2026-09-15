(function () {
    'use strict';

    const STORAGE_KEY = 'json-excel-theme';

    const DARK_THEME = 'dark';
    const LIGHT_THEME = 'light';


    /*
    ============================================================
    TAMAÑO UNIFICADO DEL BOTÓN DE TEMA
    ============================================================
    */

    function applyThemeButtonStyle() {

        if (document.getElementById('json-excel-theme-style')) {
            return;
        }

        const style = document.createElement('style');

        style.id = 'json-excel-theme-style';

        style.textContent = `

            /*
            ========================================================
            BOTÓN DE TEMA UNIFICADO
            ========================================================
            */

            .theme-button,
            #themeButton,
            [data-theme-toggle] {

                width: 36px !important;

                height: 36px !important;

                min-width: 36px !important;

                min-height: 36px !important;

                max-width: 36px !important;

                max-height: 36px !important;

                padding: 0 !important;

                margin: 0 !important;

                display: flex !important;

                align-items: center !important;

                justify-content: center !important;

                box-sizing: border-box !important;

                border-radius: 50% !important;

                font-size: 16px !important;

                line-height: 1 !important;

                cursor: pointer;

            }

        `;

        document.head.appendChild(style);
    }


    /*
    ============================================================
    OBTENER TEMA GUARDADO
    ============================================================
    */

    function getSavedTheme() {

        const savedTheme =
            localStorage.getItem(STORAGE_KEY);

        if (
            savedTheme === DARK_THEME ||
            savedTheme === LIGHT_THEME
        ) {
            return savedTheme;
        }

        return DARK_THEME;
    }


    /*
    ============================================================
    APLICAR TEMA
    ============================================================
    */

    function applyTheme(theme) {

        if (
            theme !== DARK_THEME &&
            theme !== LIGHT_THEME
        ) {
            theme = DARK_THEME;
        }

        document.documentElement.setAttribute(
            'data-theme',
            theme
        );

        localStorage.setItem(
            STORAGE_KEY,
            theme
        );

        updateThemeButtons(theme);
    }


    /*
    ============================================================
    ACTUALIZAR ICONOS
    ============================================================
    */

    function updateThemeButtons(theme) {

        const buttons =
            document.querySelectorAll(
                '[data-theme-toggle], #themeButton, .theme-button'
            );

        buttons.forEach(function (button) {

            if (theme === DARK_THEME) {

                button.textContent = '☀️';

                button.setAttribute(
                    'title',
                    'Cambiar a tema claro'
                );

                button.setAttribute(
                    'aria-label',
                    'Cambiar a tema claro'
                );

            } else {

                button.textContent = '🌙';

                button.setAttribute(
                    'title',
                    'Cambiar a tema oscuro'
                );

                button.setAttribute(
                    'aria-label',
                    'Cambiar a tema oscuro'
                );

            }

        });
    }


    /*
    ============================================================
    CAMBIAR TEMA
    ============================================================
    */

    function toggleTheme() {

        const currentTheme =
            document.documentElement.getAttribute(
                'data-theme'
            ) || DARK_THEME;

        const newTheme =
            currentTheme === DARK_THEME
                ? LIGHT_THEME
                : DARK_THEME;

        applyTheme(newTheme);
    }


    /*
    ============================================================
    CONECTAR BOTONES
    ============================================================
    */

    function connectThemeButtons() {

        const buttons =
            document.querySelectorAll(
                '[data-theme-toggle], #themeButton, .theme-button'
            );

        buttons.forEach(function (button) {

            if (
                button.dataset.themeConnected === 'true'
            ) {
                return;
            }

            button.addEventListener(
                'click',
                toggleTheme
            );

            button.dataset.themeConnected =
                'true';

        });
    }


    /*
    ============================================================
    CAMBIO DE TEMA ENTRE PESTAÑAS
    ============================================================
    */

    window.addEventListener(
        'storage',
        function (event) {

            if (event.key === STORAGE_KEY) {

                const theme =
                    event.newValue || DARK_THEME;

                applyTheme(theme);

            }

        }
    );


    /*
    ============================================================
    INICIALIZAR
    ============================================================
    */

    function initializeTheme() {

        /*
        Primero aplicamos el estilo universal
        */
        applyThemeButtonStyle();


        /*
        Obtenemos el tema guardado
        */
        const theme =
            getSavedTheme();


        /*
        Aplicamos el tema
        */
        document.documentElement.setAttribute(
            'data-theme',
            theme
        );


        /*
        Conectamos botones
        */
        connectThemeButtons();


        /*
        Actualizamos iconos
        */
        updateThemeButtons(theme);

    }


    /*
    ============================================================
    INICIO
    ============================================================
    */

    if (
        document.readyState === 'loading'
    ) {

        document.addEventListener(
            'DOMContentLoaded',
            initializeTheme
        );

    } else {

        initializeTheme();

    }


    /*
    ============================================================
    API GLOBAL
    ============================================================
    */

    window.JsonExcelTheme = {

        get: function () {

            return getSavedTheme();

        },

        set: function (theme) {

            applyTheme(theme);

        },

        toggle: function () {

            toggleTheme();

        }

    };

})();