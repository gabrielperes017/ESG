<?php
declare(strict_types=1);

require __DIR__ . '/includes/sessao.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_valido($_POST['csrf'] ?? null)) {
    header('Location: ' . (usuario_logado() ? 'dashboard.php' : 'login.php'));
    exit;
}

encerrar_sessao();

header('Location: login.php?saiu=1');
exit;
