<?php
declare(strict_types=1);
require __DIR__ . '/includes/sessao.php';

header('Location: ' . (usuario_logado() ? 'dashboard.php' : 'login.php'));
exit;
