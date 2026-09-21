<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth.php';
logout_and_destroy();
header('Location: login.php');
exit;