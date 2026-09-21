<?php
$page_title = 'Consulta de resguardo';
$page_js    = 'consulta.js';
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth.php';
$user = require_auth();
require_once __DIR__ . '/../includes/header.php';
?>

<section class="page-header">
    <div>
        <h1>🔍 Consulta de Resguardo</h1>
        <p>Captura el número de nómina del resguardante para ver sus bienes asignados.</p>
        <?php if (!$user['es_superusuario']): ?>
            <p class="badge badge--info">📌 Solo verás registros de tu área: <strong><?= e($user['area_nombre']) ?></strong></p>
        <?php endif; ?>
    </div>
</section>

<div class="card search-card">
    <h2>Búsqueda por Nómina</h2>
    <p>Ingresa el número de nómina del empleado y pulsa <strong>Buscar</strong>.</p>
    <form id="searchForm" class="search-form" autocomplete="off">
        <input type="number" id="nomina" name="nomina" min="1" placeholder="Ej. 1001" required>
        <button type="submit" class="btn btn--primary">🔎 Buscar</button>
    </form>
</div>

<div id="resultContainer"></div>

<!-- Modal de vista de resguardo para impresión -->
<div class="modal-backdrop" id="resguardoModal">
    <div class="modal" style="max-width: 1000px;">
        <div class="modal__header">
            <h2 class="modal__title">📄 Resguardo</h2>
            <button class="modal__close" onclick="App.closeModal('resguardoModal')" aria-label="Cerrar">×</button>
        </div>
        <div class="modal__body" id="resguardoBody" style="padding: 0;"></div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>