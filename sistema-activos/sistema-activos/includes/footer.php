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
    const enlace = document.querySelector('.nav--consulta-animada');

    if (!enlace) return;

    enlace.addEventListener('click', function (event) {
        if (
            event.button !== 0 ||
            event.ctrlKey ||
            event.metaKey ||
            event.shiftKey ||
            event.altKey
        ) return;

        const icono = enlace.querySelector('.nav__icon');

        if (!icono || window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
            return;
        }

        event.preventDefault();

        if (enlace.dataset.animando === '1') return;
        enlace.dataset.animando = '1';

        // Reproducir el GIF desde el inicio
        icono.src = icono.dataset.gif + '?play=' + Date.now();

        // Entrar al módulo después de la animación
        setTimeout(function () {
            window.location.href = enlace.href;
        }, 1250);
    });
});

document.addEventListener('DOMContentLoaded', () => {
  document.querySelectorAll('a.nav--icono-animado').forEach(enlace => {
    enlace.addEventListener('click', event => {
      if (event.defaultPrevented || event.button !== 0 ||
          event.ctrlKey || event.metaKey || event.shiftKey || event.altKey ||
          enlace.target === '_blank' ||
          window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;

      event.preventDefault();
      if (enlace.classList.contains('animando')) return;
      enlace.classList.add('animando');
      setTimeout(() => window.location.assign(enlace.href), 550);
    });
  });
});
</script>

</body>
</html>
