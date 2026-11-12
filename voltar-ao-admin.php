<?php
declare(strict_types=1);

require __DIR__ . '/includes/sessao.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_valido($_POST['csrf'] ?? null) && personificando()) {
    encerrar_personificacao();
    header('Location: clientes.php');
    exit;
}

header('Location: ' . (usuario_logado() ? 'dashboard.php' : 'login.php'));
exit;
