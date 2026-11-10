<?php
/**
 * Footer compartido.
 * Espera que la variable $assetsBase esté definida por header.php.
 * Si no lo está (ej. incluido aislado), la calcula.
 */
if (!isset($assetsBase)) {
    $assetsBase = base_url() . '/assets/';
}
?>    
    
    </main>

    <footer class="footer">
        <div class="container">
            <p>&copy; <?= date('Y') ?> <?= e(APP_NAME) ?> · v<?= e(APP_VERSION) ?></p>
        </div>
    </footer>

    <div id="toast" class="toast" role="status" aria-live="polite"></div>

    <?php $assetVer = defined('APP_VERSION') ? APP_VERSION : '1'; ?>
    <script src="<?= e($assetsBase) ?>js/app.js?v=<?= e($assetVer) ?>"></script>
    <?php if (isset($page_js)): ?>
    <script src="<?= e($assetsBase) ?>js/<?= e($page_js) ?>?v=<?= e($page_js_version ?? $assetVer) ?>"></script>
<?php endif; ?>

<script>
document.addEventListener('DOMContentLoaded', function () {
    // Estos botones no tienen href: Edge/Chrome no muestran una URL al pasar el cursor.
    // Solo se aplica al encabezado (menu, logo y menu de usuario).
    document.querySelectorAll('.topbar button[data-navegar]').forEach(function (boton) {
        boton.addEventListener('click', function () {
            const destino = boton.dataset.navegar;
            if (!destino || boton.dataset.navegando === '1') return;

            const animacionReducida = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

            // Mantener el GIF del Dashboard tal como funcionaba antes.
            if (boton.classList.contains('nav--consulta-animada') && !animacionReducida) {
                const icono = boton.querySelector('.nav__icon');
                if (icono && icono.dataset.gif) {
                    boton.dataset.navegando = '1';
                    icono.src = icono.dataset.gif + '?play=' + Date.now();
                    setTimeout(function () { window.location.assign(destino); }, 1250);
                    return;
                }
            }

            // Mantener las animaciones de los iconos del resto del menu.
            if (boton.classList.contains('nav--icono-animado') && !animacionReducida) {
                boton.dataset.navegando = '1';
                boton.classList.add('animando');
                setTimeout(function () { window.location.assign(destino); }, 550);
                return;
            }

            window.location.assign(destino);
        });
    });
});
</script>

</body>
</html>
