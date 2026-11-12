<?php
declare(strict_types=1);

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/limite_tentativas.php';

function senha_provisoria_sugerida(): string
{
    $alfabeto = 'abcdefghijkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $senha    = '';
    for ($indice = 0; $indice < 10; $indice++) {
        $senha .= $alfabeto[random_int(0, strlen($alfabeto) - 1)];
    }
    return $senha;
}

function conta_para_troca_de_senha(PDO $pdo, int $id): ?array
{
    $consulta = $pdo->prepare(
        'SELECT u.id, u.nome, u.email, u.telefone, u.status_conta, p.nome AS perfil
           FROM usuarios u JOIN perfis_acesso p ON p.id = u.perfil_id
          WHERE u.id = :id LIMIT 1'
    );
    $consulta->execute([':id' => $id]);
    return $consulta->fetch() ?: null;
}

function admin_trocar_senha(PDO $pdo, array $admin, array $conta, string $nova): ?string
{
    if (($admin['perfil'] ?? '') !== 'Administrador') {
        return 'Só o Administrador pode trocar a senha de outra pessoa.';
    }

    if ((int) $conta['id'] === (int) $admin['id']) {
        return 'Para trocar a sua própria senha, use a tela Perfil.';
    }

    $motivo = mensagem_senha_invalida($nova, (string) $conta['email'], 'A nova senha');
    if ($motivo !== null) {
        return $motivo;
    }

    $pdo->prepare('UPDATE usuarios SET senha = :senha WHERE id = :id')
        ->execute([':senha' => password_hash($nova, PASSWORD_DEFAULT), ':id' => (int) $conta['id']]);

    limite_zerar($pdo, 'login_conta', (string) $conta['email']);
    $digitosTelefone = telefone_digitos((string) ($conta['telefone'] ?? ''));
    if ($digitosTelefone !== '') {
        limite_zerar($pdo, 'login_conta', $digitosTelefone);
    }
    limite_zerar($pdo, 'troca_senha', (string) $conta['id']);

    return null;
}
