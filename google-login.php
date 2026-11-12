<?php
declare(strict_types=1);

require __DIR__ . '/includes/sessao.php';
require_once __DIR__ . '/includes/google.php';

if (usuario_logado() !== null) {
    header('Location: dashboard.php');
    exit;
}

if (!google_configurado()) {
    $_SESSION['erro_login'] = 'O login com Google ainda não foi configurado neste sistema.';
    header('Location: login.php');
    exit;
}

$state    = bin2hex(random_bytes(24));
$nonce    = bin2hex(random_bytes(24));
$verifier = base64url(random_bytes(48));

$_SESSION['google'] = ['state' => $state, 'nonce' => $nonce, 'verifier' => $verifier, 'hora' => time()];

header('Location: ' . google_url_autorizacao($state, $nonce, $verifier));
exit;
