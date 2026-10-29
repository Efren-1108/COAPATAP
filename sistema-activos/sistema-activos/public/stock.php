<?php
$page_title='Stock · Almacén general';
require_once __DIR__.'/../config/config.php';
require_once __DIR__.'/../includes/auth.php';
$user=require_super();
require_once __DIR__.'/../includes/header.php';
?>
<section class="page-header"><div><h1>🏬 Stock — Almacén general</h1><p>Bienes resguardados, disponibles o suspendidos. Las entradas y salidas quedan registradas.</p></div><a class="btn btn--primary" href="activos.php">➕ Registrar activo</a></section>
<div class="toolbar"><input id="buscarStock" placeholder="Buscar descripción, inventario o marca" aria-label="Buscar activos en stock"><button class="btn btn--ghost" id="recargarStock">Actualizar</button></div>
<div id="resumenStock" class="stats"></div>
<div class="table-wrap"><table class="table"><thead><tr><th>ID</th><th>Activo</th><th>Inventario</th><th>Estado físico</th><th>Situación</th><th>Costo</th><th>Entrada</th><th>Acciones</th></tr></thead><tbody id="stockTabla"></tbody></table></div>
<h2>Enviar un activo existente al almacén</h2><p>Busca el ID del activo en la sección Activos. El movimiento retirará al responsable y al área actuales, sin eliminar el bien.</p>
<div class="toolbar"><input id="stockId" type="number" min="1" placeholder="ID del activo"><button id="btnEntrada" class="btn btn--primary">📦 Enviar a stock</button></div>
<script src="<?= e(base_url()) ?>/assets/js/stock.js"></script>
<?php require_once __DIR__.'/../includes/footer.php'; ?>
