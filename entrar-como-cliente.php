<?php
declare(strict_types=1);

require __DIR__ . '/config/database.php';
require __DIR__ . '/includes/sessao.php';
require __DIR__ . '/includes/layout.php';

exigir_login();
exigir_perfil(['Administrador'], 'clientes');

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_valido($_POST['csrf'] ?? null)) {
    header('Location: clientes.php');
    exit;
}

$idPostado = $_POST['id'] ?? 0;
$clienteId = is_scalar($idPostado) ? (int) $idPostado : 0;

try {
    $consulta = conectar()->prepare(
        "SELECT u.id, u.nome, u.email, u.perfil_id, u.status_conta
           FROM usuarios u JOIN perfis_acesso p ON p.id = u.perfil_id
          WHERE u.id = :id AND p.nome = 'Cliente' LIMIT 1"
    );
    $consulta->execute([':id' => $clienteId]);
    $cliente = $consulta->fetch();
} catch (PDOException $excecao) {
    registrar_erro_banco('Entrar como cliente', $excecao);
    $_SESSION['flash'] = 'Não foi possível abrir a conta do cliente agora. Tente de novo em instantes.';
    header('Location: clientes.php');
    exit;
}

if (!$cliente) {
    $_SESSION['flash'] = 'Cliente não encontrado.';
    header('Location: clientes.php');
    exit;
}
if ($cliente['status_conta'] !== 'Ativa') {
    $_SESSION['flash'] = 'A conta de ' . $cliente['nome'] . ' está inativa. Ative a conta antes de entrar como cliente.';
    header('Location: clientes.php');
    exit;
}

error_log(sprintf('[Bio Oleo] Administrador #%d entrou como cliente #%d', usuario_logado()['id'], $cliente['id']));

if (!personificando()) {
    iniciar_personificacao($cliente);
}

header('Location: dashboard.php');
exit;
