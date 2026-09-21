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