<?php
$page_title='Stock · Almacén general';
$page_js='stock.js';
$page_js_version='stock-fechas-legibles-20261029-1';
require_once __DIR__.'/../config/config.php';
require_once __DIR__.'/../includes/auth.php';
$user=require_super();
require_once __DIR__.'/../includes/header.php';
?>
<section class="page-header"><div><h1>🏬 Stock — Almacén general</h1><p>Bienes resguardados, disponibles o suspendidos. Las entradas y salidas quedan registradas.</p></div><a class="btn btn--primary" href="activos.php">➕ Registrar activo</a></section>
<div class="toolbar"><input id="buscarStock" placeholder="Buscar descripción, inventario o marca" aria-label="Buscar activos en stock"></div>
<div id="resumenStock" class="stats"></div>
<div class="table-wrap stock-table-wrap"><table class="table stock-table"><thead><tr><th>Activo</th><th>Situación</th><th>Entrada</th><th>Acciones</th></tr></thead><tbody id="stockTabla"></tbody></table></div>
<style>
.stock-table { width: 100%; table-layout: auto; }
.stock-table th:last-child, .stock-table td:last-child { min-width: 145px; width: 145px; }
.stock-table .stock-actions { display:flex; flex-direction:column; gap:6px; align-items:stretch; }
.stock-table .stock-actions button { display:block; width:100%; min-width:0; white-space:normal; padding:7px 8px; }
.stock-table-wrap { overflow-x:auto; }
</style>
<style>
.coapatap-dialog-overlay{position:fixed;inset:0;background:rgba(15,23,42,.58);z-index:99999;display:flex;align-items:center;justify-content:center;padding:18px}
.coapatap-dialog{background:#fff;color:#17243b;width:min(100%,530px);max-height:90vh;overflow:auto;border-radius:16px;box-shadow:0 20px 60px rgba(0,0,0,.25);font-family:inherit}
.coapatap-dialog-header{display:flex;align-items:center;justify-content:space-between;gap:15px;padding:20px 24px;border-bottom:1px solid #e2e8f0}
.coapatap-dialog-header h2{font-size:1.2rem;margin:0;color:#13284b}
.coapatap-dialog-x{background:none;border:0;font-size:27px;cursor:pointer;color:#64748b}
.coapatap-dialog-body{padding:20px 24px}
.coapatap-dialog-body p{margin:0 0 16px;line-height:1.55}
.coapatap-dialog-label{display:block;font-weight:600;margin-bottom:8px}
.coapatap-dialog-input{box-sizing:border-box;width:100%;padding:12px;border:1px solid #cbd5e1;border-radius:9px;background:#fff;color:#17243b;font:inherit}
.coapatap-dialog-input:focus{outline:2px solid #2563eb;border-color:#2563eb}
.coapatap-dialog-invalid{border-color:#dc2626}
.coapatap-dialog-footer{display:flex;justify-content:flex-end;gap:10px;padding:15px 24px 20px;border-top:1px solid #e2e8f0}
.coapatap-dialog-history{max-height:350px;overflow:auto}
.coapatap-dialog-history-row{padding:12px 0;border-bottom:1px solid #e2e8f0}
.coapatap-dialog-history-row strong{color:#2563eb}
.coapatap-dialog-history-row small{display:block;color:#64748b;margin-top:3px}
.coapatap-dialog-history-row p{margin:6px 0 0}
</style>

<?php require_once __DIR__.'/../includes/footer.php'; ?>
