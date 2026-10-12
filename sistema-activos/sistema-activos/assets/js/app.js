/* =====================================================
   app.js — Utilidades compartidas
   ===================================================== */

const App = (() => {
    /** API helper */
    async function api(url, options = {}) {
        // Si la URL no es absoluta (http:// o https://), anteponer API_BASE.
        if (!/^https?:\/\//i.test(url)) {
            const base = (typeof window !== 'undefined' && window.API_BASE) || '/api/';
            url = base + url.replace(/^\/+/, '');
        }
        const opts = {
            method: options.method || 'GET',
            credentials: 'same-origin', // enviar cookies de sesión
            headers: { 'Content-Type': 'application/json' },
            ...options,
        };
        if (options.body && typeof options.body !== 'string') {
            opts.body = JSON.stringify(options.body);
        }
        const res = await fetch(url, opts);
        let data = null;
        try { data = await res.json(); } catch (e) { /* noop */ }
        if (res.status === 401) {
            // Sesión expirada o no autenticado → mandar al login
            const path = window.location.pathname;
            const inPublic = path.includes('/public/');
            // Calcular la ruta al login a partir de la raíz del proyecto
            const loginUrl = inPublic
                ? path.replace(/\/public\/.*$/, '/public/login.php')
                : path.replace(/\/[^/]*$/, '/public/login.php');
            window.location.href = loginUrl;
            return;
        }
        if (!res.ok) {
            throw new Error((data && data.error) || `HTTP ${res.status}`);
        }
        return data;
    }

    /** Toast */
    let toastTimer = null;
    function toast(message, type = 'info', duration = 3500) {
        const el = document.getElementById('toast');
        if (!el) return;
        el.textContent = message;
        el.className = 'toast is-show toast--' + type;
        clearTimeout(toastTimer);
        toastTimer = setTimeout(() => {
            el.className = 'toast';
        }, duration);
    }

    /** Modal helpers */
    function openModal(id) {
        const m = document.getElementById(id);
        if (m) m.classList.add('is-open');
    }
    function closeModal(id) {
        const m = document.getElementById(id);
        if (m) m.classList.remove('is-open');
    }

    /** Confirm dialog */
    function confirm(message) {
        return Promise.resolve(window.confirm(message));
    }

    /** Format currency */
    function money(value) {
        const n = parseFloat(value || 0);
        return new Intl.NumberFormat('es-MX', {
            style: 'currency', currency: 'MXN', minimumFractionDigits: 2
        }).format(n);
    }

    /** Format date */
    function date(value) {
        if (!value) return '—';
        const d = new Date(value);
        if (isNaN(d)) return '—';
        return d.toLocaleDateString('es-MX', { year: 'numeric', month: '2-digit', day: '2-digit' });
    }

    /** Escape HTML */
    function esc(str) {
        if (str == null) return '';
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    /** Form serialization */
    function formToObject(form) {
        const data = {};
        new FormData(form).forEach((v, k) => { data[k] = v.trim(); });
        return data;
    }

    function fillForm(form, data) {
        Array.from(form.elements).forEach(el => {
            if (!el.name) return;
            if (data[el.name] !== undefined && data[el.name] !== null) {
                el.value = data[el.name];
            } else {
                el.value = '';
            }
        });
    }

    /** Document ready */
    function ready(fn) {
        if (document.readyState !== 'loading') fn();
        else document.addEventListener('DOMContentLoaded', fn);
    }

    return { api, toast, openModal, closeModal, confirm, money, date, esc, formToObject, fillForm, ready };
})();

/* ============ NAV TOGGLE ============ */
document.addEventListener('DOMContentLoaded', () => {
    const toggle = document.getElementById('navToggle');
    const nav    = document.querySelector('.nav');
    if (toggle && nav) {
        toggle.addEventListener('click', () => nav.classList.toggle('is-open'));
    }

    /* Close modal on backdrop click */
    document.querySelectorAll('.modal-backdrop').forEach(m => {
        m.addEventListener('click', e => {
            if (e.target === m) m.classList.remove('is-open');
        });
    });

    /* Close modal on Esc */
    document.addEventListener('keydown', e => {
        if (e.key === 'Escape') {
            document.querySelectorAll('.modal-backdrop.is-open').forEach(m => m.classList.remove('is-open'));
            // Cerrar también el menú de usuario si está abierto
            const um = document.getElementById('userMenu');
            if (um) { um.hidden = true; document.getElementById('userChip')?.setAttribute('aria-expanded', 'false'); }
        }
    });

    /* User menu toggle */
    const chip = document.getElementById('userChip');
    const menu = document.getElementById('userMenu');
    if (chip && menu) {
        chip.addEventListener('click', (e) => {
            e.stopPropagation();
            const open = !menu.hidden;
            menu.hidden = open;
            chip.setAttribute('aria-expanded', open ? 'false' : 'true');
        });
        document.addEventListener('click', (e) => {
            if (!menu.contains(e.target) && e.target !== chip) {
                menu.hidden = true;
                chip.setAttribute('aria-expanded', 'false');
            }
        });
    }
});
