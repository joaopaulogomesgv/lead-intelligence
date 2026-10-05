/**
 * Lead Intelligence - UTM & Meta Tracking Preserver
 * 
 * Preserva parâmetros UTM e Meta Clicks (fbclid, fbc, fbp) durante toda a navegação
 * e injeta automaticamente campos ocultos nos formulários do Elementor Pro.
 */
(function () {
    'use strict';

    var config = window.liTrackingConfig || {
        cookiePrefix: 'li_',
        cookieDays: 30,
        trackingKeys: [
            'utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term',
            'fbclid', 'fbc', 'fbp', 'campaign_id', 'adset_id', 'ad_id',
            'campaign_name', 'adset_name', 'ad_name'
        ]
    };

    /**
     * Auxiliares de Cookies
     */
    function setCookie(name, value, days) {
        if (!value) return;
        var expires = '';
        if (days) {
            var date = new Date();
            date.setTime(date.getTime() + (days * 24 * 60 * 60 * 1000));
            expires = '; expires=' + date.toUTCString();
        }
        document.cookie = name + '=' + encodeURIComponent(value) + expires + '; path=/; SameSite=Lax';
    }

    function getCookie(name) {
        var nameEQ = name + '=';
        var ca = document.cookie.split(';');
        for (var i = 0; i < ca.length; i++) {
            var c = ca[i].trim();
            if (c.indexOf(nameEQ) === 0) {
                return decodeURIComponent(c.substring(nameEQ.length));
            }
        }
        return '';
    }

    /**
     * Captura parâmetros da URL atual
     */
    function getUrlParams() {
        var params = {};
        var search = window.location.search.substring(1);
        if (!search) return params;

        var pairs = search.split('&');
        for (var i = 0; i < pairs.length; i++) {
            var pair = pairs[i].split('=');
            if (pair.length === 2) {
                var key = decodeURIComponent(pair[0]).toLowerCase();
                var val = decodeURIComponent(pair[1].replace(/\+/g, ' '));
                params[key] = val;
            }
        }
        return params;
    }

    /**
     * Processa e armazena os parâmetros
     */
    function processTracking() {
        var urlParams = getUrlParams();
        var storageAvailable = typeof window.localStorage !== 'undefined';

        // Tratamento especial para fbclid e cookies da Meta (_fbc e _fbp)
        if (urlParams.fbclid) {
            var fbclid = urlParams.fbclid;
            setCookie(config.cookiePrefix + 'fbclid', fbclid, config.cookieDays);
            if (storageAvailable) {
                localStorage.setItem('li_fbclid', fbclid);
            }

            // Se o cookie nativo _fbc não existir, cria no formato oficial da Meta
            if (!getCookie('_fbc')) {
                var fbcVal = 'fb.1.' + (+new Date()) + '.' + fbclid;
                setCookie('_fbc', fbcVal, config.cookieDays);
            }
        }

        // Garante cookie _fbp (navegador/dispositivo) se não existir
        if (!getCookie('_fbp')) {
            var randomId = Math.floor(Math.random() * 8999999999 + 1000000000);
            var fbpVal = 'fb.1.' + (+new Date()) + '.' + randomId;
            setCookie('_fbp', fbpVal, config.cookieDays);
        }

        // Processa todas as chaves monitoradas
        for (var i = 0; i < config.trackingKeys.length; i++) {
            var key = config.trackingKeys[i];
            var val = urlParams[key];

            // Se veio na URL, salva no cookie e storage
            if (val) {
                setCookie(config.cookiePrefix + key, val, config.cookieDays);
                if (storageAvailable) {
                    localStorage.setItem('li_' + key, val);
                }
            }
        }
    }

    /**
     * Obtém o valor consolidado de uma chave (Cookie > LocalStorage > Meta Cookies)
     */
    function getStoredValue(key) {
        var val = getCookie(config.cookiePrefix + key);
        if (!val && typeof window.localStorage !== 'undefined') {
            val = localStorage.getItem('li_' + key);
        }

        // Fallbacks para cookies nativos da Meta
        if (!val && key === 'fbc') {
            val = getCookie('_fbc');
        }
        if (!val && key === 'fbp') {
            val = getCookie('_fbp');
        }

        return val || '';
    }

    /**
     * Injeta campos ocultos em formulários Elementor
     */
    function injectHiddenInputs() {
        var forms = document.querySelectorAll('form.elementor-form, form[id*="elementor"], .elementor form');
        if (!forms || forms.length === 0) return;

        for (var f = 0; f < forms.length; f++) {
            var form = forms[f];

            for (var k = 0; k < config.trackingKeys.length; k++) {
                var key = config.trackingKeys[k];
                var val = getStoredValue(key);
                if (!val) continue;

                // Verifica se já existe o campo no formulário
                var existingInput = form.querySelector('input[name="' + key + '"]');
                if (existingInput) {
                    if (!existingInput.value) {
                        existingInput.value = val;
                    }
                } else {
                    var input = document.createElement('input');
                    input.type = 'hidden';
                    input.name = key;
                    input.value = val;
                    form.appendChild(input);
                }
            }
        }
    }

    // Executa captura inicial imediatamente
    processTracking();

    // Injeta nos formulários quando o DOM estiver pronto
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', injectHiddenInputs);
    } else {
        injectHiddenInputs();
    }

    // Monitora formulários adicionados dinamicamente (ex: Popups do Elementor)
    if (typeof window.MutationObserver !== 'undefined') {
        var observer = new MutationObserver(function (mutations) {
            injectHiddenInputs();
        });
        observer.observe(document.body || document.documentElement, {
            childList: true,
            subtree: true
        });
    }

})();
