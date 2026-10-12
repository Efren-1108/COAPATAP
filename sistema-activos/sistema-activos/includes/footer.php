    </main>

    <footer class="footer">
        <div class="container">
            <p>&copy; <?= date('Y') ?> <?= e(APP_NAME) ?> · v<?= e(APP_VERSION) ?></p>
        </div>
    </footer>

    <div id="toast" class="toast" role="status" aria-live="polite"></div>

    <script src="<?= e($assetsBase) ?>js/app.js"></script>
    <?php if (isset($page_js)): ?>
        <script src="<?= e($assetsBase) ?>js/<?= e($page_js) ?>"></script>
    <?php endif; ?>
</body>
</html>
