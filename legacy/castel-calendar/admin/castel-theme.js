/**
 * Tema automático según hora de Chile (America/Santiago):
 * oscuro de 19:00 a 6:59; claro de 7:00 a 18:59.
 * El usuario puede fijar tema manualmente; se guarda en localStorage
 * bajo castel-theme-manual-override. Sin override, aplica el horario.
 */
(function (global) {
    var TZ = 'America/Santiago';
    var NIGHT_HOUR_START = 19; // 7 PM
    var DAY_HOUR_START = 7;    // 7 AM (modo claro a partir de aquí)
    var STORAGE_THEME = 'castel-theme';
    var STORAGE_OVERRIDE = 'castel-theme-manual-override';

    function chileHour(date) {
        var d = date || new Date();
        var parts;
        try {
            parts = new Intl.DateTimeFormat('en-GB', {
                timeZone: TZ,
                hour: 'numeric',
                hour12: false
            }).formatToParts(d);
        } catch (e) {
            return d.getHours();
        }
        var h = 0;
        for (var i = 0; i < parts.length; i++) {
            if (parts[i].type === 'hour') {
                h = parseInt(parts[i].value, 10);
                break;
            }
        }
        return isNaN(h) ? 0 : h;
    }

    function isNightChile(date) {
        var h = chileHour(date);
        return h >= NIGHT_HOUR_START || h < DAY_HOUR_START;
    }

    function themeFromSchedule(date) {
        return isNightChile(date) ? 'dark' : 'light';
    }

    function getOverride() {
        try {
            return localStorage.getItem(STORAGE_OVERRIDE) === '1';
        } catch (e) {
            return false;
        }
    }

    function getStoredTheme() {
        try {
            var t = localStorage.getItem(STORAGE_THEME);
            return t === 'dark' || t === 'light' ? t : null;
        } catch (e) {
            return null;
        }
    }

    function resolveInitialTheme() {
        if (getOverride()) {
            var s = getStoredTheme();
            if (s) return s;
        }
        return themeFromSchedule();
    }

    function isPageDark() {
        if (document.documentElement.getAttribute('data-theme') === 'dark') {
            return true;
        }
        if (document.documentElement.getAttribute('data-theme') === 'light') {
            return false;
        }
        if (document.body) {
            if (document.body.getAttribute('data-theme') === 'dark') {
                return true;
            }
            if (document.body.getAttribute('data-theme') === 'light') {
                return false;
            }
        }
        if (document.body && (document.body.classList.contains('theme-dark') || document.body.classList.contains('dark-mode'))) {
            return true;
        }
        if (document.documentElement.classList.contains('theme-dark') || document.documentElement.classList.contains('dark')) {
            return true;
        }
        return false;
    }

    function isInstitutionalLogoImg(img) {
        if (!img || !img.getAttribute) return false;
        var srcRaw =
            (img.getAttribute('src') || '') +
            ' ' +
            (img.getAttribute('srcset') || '') +
            ' ' +
            (typeof img.currentSrc === 'string' ? img.currentSrc : '') +
            ' ' +
            (img.src || '');
        var src = String(srcRaw).toLowerCase();
        if (src.indexOf('castelgandolfo') !== -1) return true;
        if (src.indexOf('roomkeeper') !== -1) return true;
        if (src.indexOf('logocastel') !== -1) return true;
        if (src.indexOf('logo-castel') !== -1) return true;
        if (src.indexOf('logo_caste') !== -1) return true;
        var alt = (img.getAttribute('alt') || '').toLowerCase();
        if (alt.indexOf('castelgandolfo') !== -1) return true;
        if (alt.indexOf('castel') !== -1 && (alt.indexOf('colegio') !== -1 || alt.indexOf('logo') !== -1)) {
            return true;
        }
        var cls = (img.className && String(img.className).toLowerCase()) || '';
        if (cls.indexOf('site-logo') !== -1 || cls.indexOf('logo-castel') !== -1 || cls.indexOf('brand-logo') !== -1) {
            return true;
        }
        if (img.closest) {
            if (img.closest('a.site-logo, .site-logo, .brand-lockup, .site-header__bar > a, header .container > a:first-of-type')) {
                if (alt.indexOf('colegio') !== -1 || alt.indexOf('castel') !== -1) return true;
            }
        }
        return false;
    }

    function ensureEscudoPadStyle() {
        if (!global.document || !document.head) return;
        if (document.getElementById('ccg-escudo-pad-style')) return;
        var s = document.createElement('style');
        s.id = 'ccg-escudo-pad-style';
        s.textContent =
            'img.ccg-escudo-pad{background:#f0f4f8!important;border-radius:14px!important;padding:5px 7px!important;box-sizing:content-box!important;box-shadow:0 0 0 1px rgba(255,255,255,.25) inset!important;mix-blend-mode:normal!important;}';
        document.head.appendChild(s);
    }

    function syncInstitutionalLogoPad() {
        if (!global.document || !document.documentElement) return;
        ensureEscudoPadStyle();
        var dark = isPageDark();
        var imgs = document.getElementsByTagName('img');
        var k = 0;
        for (; k < imgs.length; k++) {
            var img = imgs[k];
            if (!isInstitutionalLogoImg(img)) continue;
            img.classList.toggle('ccg-escudo-pad', dark);
        }
    }

    function updateToggleElements(theme) {
        document.querySelectorAll('[data-theme-toggle]').forEach(function (el) {
            if (el.querySelector) {
                var icon = el.querySelector('.theme-toggle__icon');
                var label = el.querySelector('.theme-toggle__label');
                if (icon || label) {
                    if (icon) icon.textContent = theme === 'dark' ? '☀' : '☾';
                    if (label) label.textContent = theme === 'dark' ? 'Claro' : 'Oscuro';
                    el.setAttribute('aria-pressed', theme === 'dark' ? 'true' : 'false');
                    el.setAttribute('title', theme === 'dark' ? 'Cambiar a tema claro' : 'Cambiar a tema oscuro');
                } else {
                    el.textContent = theme === 'dark' ? 'Claro' : 'Oscuro';
                }
            } else {
                el.textContent = theme === 'dark' ? 'Claro' : 'Oscuro';
            }
        });
        syncInstitutionalLogoPad();
    }

    function applyToDom(theme, isManual) {
        document.documentElement.setAttribute('data-theme', theme);
        try {
            if (isManual) {
                localStorage.setItem(STORAGE_OVERRIDE, '1');
                localStorage.setItem(STORAGE_THEME, theme);
            }
        } catch (e) {}
        updateToggleElements(theme);
    }

    function resyncToSchedule() {
        try {
            localStorage.removeItem(STORAGE_OVERRIDE);
        } catch (e) {}
        var t = themeFromSchedule();
        document.documentElement.setAttribute('data-theme', t);
        try {
            localStorage.setItem(STORAGE_THEME, t);
        } catch (e) {}
        updateToggleElements(t);
    }

    /** Aplica tema inicial en <html> (sin tocar localStorage de override). */
    function applyInitialBlocking() {
        var t = resolveInitialTheme();
        document.documentElement.setAttribute('data-theme', t);
    }

    var api = {
        chileHour: chileHour,
        isNightChile: isNightChile,
        themeFromSchedule: themeFromSchedule,
        resolveInitialTheme: resolveInitialTheme,
        getOverride: getOverride,
        applyToDom: applyToDom,
        resyncToSchedule: resyncToSchedule,
        updateToggleElements: updateToggleElements,
        syncInstitutionalLogoPad: syncInstitutionalLogoPad,
        STORAGE_THEME: STORAGE_THEME,
        STORAGE_OVERRIDE: STORAGE_OVERRIDE,
        TIMEZONE: TZ
    };

    global.CASTEL_SCHEDULED_THEME = api;

    applyInitialBlocking();

    function tickSchedule() {
        if (getOverride()) return;
        var want = themeFromSchedule();
        var cur = document.documentElement.getAttribute('data-theme') || 'light';
        if (cur !== want) {
            document.documentElement.setAttribute('data-theme', want);
            try {
                localStorage.setItem(STORAGE_THEME, want);
            } catch (e) {}
            updateToggleElements(want);
        }
    }

    function bootEscudoObservers() {
        if (global.__ccgEscudoHooked) return;
        global.__ccgEscudoHooked = true;
        var moRun = function () {
            syncInstitutionalLogoPad();
        };
        try {
            new MutationObserver(moRun).observe(document.documentElement, { attributes: true, attributeFilter: ['data-theme', 'class'] });
        } catch (e) {}
        if (document.body) {
            try {
                new MutationObserver(moRun).observe(document.body, { attributes: true, attributeFilter: ['data-theme', 'class'] });
            } catch (e) {}
        } else {
            document.addEventListener('DOMContentLoaded', function bEsc() {
                document.removeEventListener('DOMContentLoaded', bEsc);
                try {
                    new MutationObserver(moRun).observe(document.body, { attributes: true, attributeFilter: ['data-theme', 'class'] });
                } catch (e) {}
            });
        }
        var n = 0;
        var escudoRaf = setInterval(function () {
            moRun();
            if (++n >= 36) {
                clearInterval(escudoRaf);
            }
        }, 450);
    }

    if (global.document) {
        if (global.document.readyState === 'loading') {
            global.document.addEventListener('DOMContentLoaded', function onReady() {
                document.removeEventListener('DOMContentLoaded', onReady);
                updateToggleElements(document.documentElement.getAttribute('data-theme') || 'light');
                bootEscudoObservers();
            });
        } else {
            updateToggleElements(document.documentElement.getAttribute('data-theme') || 'light');
            bootEscudoObservers();
        }
        setInterval(tickSchedule, 60000);
    }
})(typeof window !== 'undefined' ? window : this);
