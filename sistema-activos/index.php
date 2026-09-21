<?php
// Redirección inteligente desde la raíz.
// - Si NO hay sesión iniciada, va directo al login.
// - Si YA hay sesión, va al inicio de la aplicación.
// Mantiene compatibilidad con instalaciones donde la URL raíz
// no apunta directamente a public/.

require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/includes/auth.php';

$hasSession = current_user() !== null;
header('Location: public/' . ($hasSession ? 'index.php' : 'login.php'), true, 302);
exit;
