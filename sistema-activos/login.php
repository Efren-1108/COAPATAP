<?php
// Acceso directo desde la raíz: redirige al login real dentro de public/.
// Permite que funcione http://localhost/login.php sin tener que escribir
// la ruta completa /sistema-activos/public/login.php.
header('Location: public/login.php', true, 302);
exit;
