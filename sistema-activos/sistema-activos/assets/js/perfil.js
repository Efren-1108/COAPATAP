/* =====================================================
   perfil.js — Cambio de contraseña del usuario actual
   ===================================================== */

const form      = document.getElementById('perfilForm');
const btn       = document.getElementById('btnGuardar');
const errBox    = document.getElementById('formError');

document.addEventListener('DOMContentLoaded', () => {
    form.addEventListener('submit', onSubmit);
});

async function onSubmit(e) {
    e.preventDefault();
    errBox.hidden = true;
    errBox.textContent = '';

    const actual    = form.actual.value;
    const nueva     = form.nueva.value;
    const confirmar = form.confirmar.value;

    if (actual.length < 8)    return showError('Ingresa tu contraseña actual (mín. 8 caracteres).');
    if (nueva.length < 8)     return showError('La nueva contraseña debe tener al menos 8 caracteres.');
    if (nueva !== confirmar)  return showError('La confirmación no coincide con la nueva contraseña.');
    if (nueva === actual)     return showError('La nueva contraseña debe ser distinta de la actual.');

    btn.disabled = true;
    btn.textContent = '⏳ Guardando...';

    try {
        const res = await App.api('auth.php', {
            method: 'POST',
            body: { accion: 'change_password', actual, nueva, confirmar }
        });
        if (!res.ok) throw new Error(res.error);
        App.toast(res.message || 'Contraseña actualizada', 'success');
        form.reset();
    } catch (err) {
        showError(err.message);
    } finally {
        btn.disabled = false;
        btn.textContent = '💾 Actualizar contraseña';
    }
}

function showError(msg) {
    errBox.textContent = msg;
    errBox.hidden = false;
}