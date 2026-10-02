<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/sicherheit.php';
td_session_start();
if (td_angemeldet()) td_protokoll('logout');
$_SESSION = [];
session_destroy();
header('Location: /login.php?logout=1');
exit;
