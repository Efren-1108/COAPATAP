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
    <script src="<?= e($assetsBase) ?>js/<?= e($page_js) ?>?v=<?= e($assetVer) ?>"></script>
<?php endif; ?>
</body>
</html>
