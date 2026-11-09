/* COAPATAP · Mejora de interfaz de importación (no altera el procesamiento PHP) */
document.addEventListener('DOMContentLoaded', () => {
    const zona = document.getElementById('importacionZonaArchivo');
    const input = document.getElementById('importacionInputArchivo');
    const nombre = document.getElementById('importacionNombreArchivo');
    if (!zona || !input || !nombre) return;

    const mostrarNombre = () => {
        const archivo = input.files && input.files[0];
        nombre.textContent = archivo ? archivo.name : 'Ningún archivo seleccionado';
    };
    input.addEventListener('change', mostrarNombre);

    ['dragenter', 'dragover'].forEach(tipo => {
        zona.addEventListener(tipo, evento => {
            evento.preventDefault();
            zona.classList.add('importacion-upload__archivo--arrastrando');
        });
    });
    ['dragleave', 'drop'].forEach(tipo => {
        zona.addEventListener(tipo, evento => {
            evento.preventDefault();
            zona.classList.remove('importacion-upload__archivo--arrastrando');
        });
    });
    zona.addEventListener('drop', evento => {
        const archivo = evento.dataTransfer?.files?.[0];
        if (!archivo) return;
        if (!/\.xlsx$/i.test(archivo.name) || archivo.size > 10 * 1024 * 1024) {
            nombre.textContent = 'Selecciona un archivo .xlsx de máximo 10 MB.';
            return;
        }
        const transferencia = new DataTransfer();
        transferencia.items.add(archivo);
        input.files = transferencia.files;
        mostrarNombre();
    });
});
