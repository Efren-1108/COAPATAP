/* =====================================================
   auth.js — Manejo del formulario de login
   ===================================================== */

const form      = document.getElementById('loginForm');
const inputUser = document.getElementById('usuario');
const inputPass = document.getElementById('password');
const btnLogin  = document.getElementById('btnLogin');
const errBox    = document.getElementById('formError');
const label     = btnLogin.querySelector('.btn__label');
const spinner   = btnLogin.querySelector('.spinner');

document.addEventListener('DOMContentLoaded', () => {
    form.addEventListener('submit', onSubmit);
});

function onSubmit(e) {
    e.preventDefault();
    errBox.hidden = true;
    errBox.textContent = '';

    const usuario  = inputUser.value.trim();
    const password = inputPass.value;

    if (usuario.length < 3) return showError('Ingresa tu nombre de usuario (mín. 3 caracteres).');
    if (password.length < 8) return showError('Ingresa tu contraseña (mín. 8 caracteres).');

    setLoading(true);

    App.api('auth.php', { method: 'POST', body: { usuario, password } })
        .then(res => {
            if (!res.ok) throw new Error(res.error || 'No se pudo iniciar sesión');
            // Redirigir al inicio (mantenemos la raíz del proyecto)
            const path = window.location.pathname;
            const target = path.includes('/public/')
                ? path.replace(/\/public\/[^/]*$/, '/public/index.php')
                : path.replace(/\/[^/]*$/, '/public/index.php');
            window.location.href = target;
        })
        .catch(err => {
            showError(err.message);
            setLoading(false);
            // Animación de shake
            form.classList.remove('shake');
            void form.offsetWidth;
            form.classList.add('shake');
        });
}

function showError(msg) {
    errBox.textContent = msg;
    errBox.hidden = false;
}

function setLoading(on) {
    btnLogin.disabled = on;
    spinner.hidden = !on;
    label.style.opacity = on ? '0.6' : '1';
}

/* Mostrar u ocultar contraseña */
(() => {
    const passwordInput = document.getElementById('password');
    const toggleButton = document.getElementById('togglePassword');
    const eyeIcon = document.getElementById('passwordEye');

    if (!passwordInput || !toggleButton || !eyeIcon) {
        return;
    }

    toggleButton.addEventListener('click', () => {
        const mostrar = passwordInput.type === 'password';

        passwordInput.type = mostrar ? 'text' : 'password';

        toggleButton.setAttribute(
            'aria-label',
            mostrar ? 'Ocultar contraseña' : 'Mostrar contraseña'
        );

        toggleButton.setAttribute(
            'title',
            mostrar ? 'Ocultar contraseña' : 'Mostrar contraseña'
        );

        toggleButton.setAttribute('aria-pressed', String(mostrar));

        eyeIcon.innerHTML = mostrar
            ? `
                <path d="M3 3l18 18"/>
                <path d="M10.6 10.6a2 2 0 0 0 2.8 2.8"/>
                <path d="M9.9 5.2A10.8 10.8 0 0 1 12 5c6.4 0 10 7 10 7a14.8 14.8 0 0 1-3 3.8"/>
                <path d="M6.6 6.6C3.6 8.5 2 12 2 12s3.6 7 10 7a10 10 0 0 0 3-.5"/>
            `
            : `
                <path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7S2 12 2 12Z"/>
                <circle cx="12" cy="12" r="3"/>
            `;
    });
})();