<?php
declare(strict_types=1);

require __DIR__ . '/config/database.php';
require __DIR__ . '/includes/sessao.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/google.php';

function google_falhar(string $mensagem, ?string $detalhe = null): never
{
    if ($detalhe !== null) {
        error_log('Login Google: ' . $detalhe);
        if (depuracao_ativa()) {
            $mensagem .= ' [DEBUG] ' . $detalhe;
        }
    }
    $_SESSION['erro_login'] = $mensagem;
    header('Location: login.php');
    exit;
}

if (usuario_logado() !== null) {
    header('Location: dashboard.php');
    exit;
}
if (!google_configurado()) {
    google_falhar('O login com Google ainda não foi configurado neste sistema.');
}

$dadosGoogle = $_SESSION['google'] ?? null;
unset($_SESSION['google']);

if (isset($_GET['error'])) {
    google_falhar('Login com Google cancelado.');
}

$state = (string) ($_GET['state'] ?? '');
$code  = (string) ($_GET['code'] ?? '');

if (!is_array($dadosGoogle) || $code === '' || !hash_equals((string) $dadosGoogle['state'], $state) || time() - (int) $dadosGoogle['hora'] > 600) {
    google_falhar('Não foi possível confirmar o login com Google. Tente de novo.');
}

try {
    $token = google_trocar_codigo($code, (string) $dadosGoogle['verifier']);
    $info  = google_validar_id_token((string) $token['id_token'], (string) $dadosGoogle['nonce']);
} catch (RuntimeException $excecao) {
    google_falhar('Não foi possível entrar com o Google agora. Tente de novo ou use e-mail e senha.', $excecao->getMessage());
}

try {
    $pdo = conectar();

    $buscar = $pdo->prepare(
        'SELECT u.id, u.nome, u.email, u.perfil_id, u.status_conta, p.nome AS perfil
           FROM usuarios u JOIN perfis_acesso p ON p.id = u.perfil_id WHERE u.email = :e LIMIT 1'
    );
    $buscar->execute([':e' => $info['email']]);
    $usuario = $buscar->fetch();

    if (!$usuario) {
        $perfil = $pdo->query("SELECT id FROM perfis_acesso WHERE nome = 'Cliente' LIMIT 1")->fetchColumn();
        if ($perfil === false) {
            google_falhar('Não foi possível criar a conta agora. Tente novamente em instantes.', 'perfil Cliente não existe');
        }
        $nome = $info['nome'] !== '' ? $info['nome'] : strstr($info['email'], '@', true);
        try {
            $pdo->prepare(
                'INSERT INTO usuarios (nome, email, senha, perfil_id, data_cadastro) VALUES (:n, :e, :s, :p, :d)'
            )->execute([
                ':n' => mb_substr((string) $nome, 0, 150),
                ':e' => $info['email'],
                ':s' => password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT),
                ':p' => (int) $perfil,
                ':d' => date('Y-m-d'),
            ]);
        } catch (PDOException $excecao) {
            if ($excecao->getCode() !== '23000') { throw $excecao; }
        }
        $buscar->execute([':e' => $info['email']]);
        $usuario = $buscar->fetch();
    }

    if (!$usuario) {
        google_falhar('Não foi possível entrar agora. Tente novamente em instantes.', 'usuário não encontrado após criar');
    }
    if ($usuario['status_conta'] !== 'Ativa') {
        google_falhar('Esta conta está inativa. Fale com o administrador.');
    }

    registrar_login($usuario);
    header('Location: dashboard.php');
    exit;
} catch (PDOException $excecao) {
    google_falhar('Não foi possível entrar agora. Tente novamente em instantes.', $excecao->getMessage());
}
