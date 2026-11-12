<?php
declare(strict_types=1);

require_once __DIR__ . '/email.php';
require_once __DIR__ . '/google.php';

const REDEFINICAO_VALIDADE_SEGUNDOS      = 3600;
const LIMITE_REDEFINICAO_POR_IP          = 5;
const LIMITE_REDEFINICAO_POR_EMAIL       = 3;
const LIMITE_LINK_INVALIDO_POR_IP        = 10;

function redefinicao_hash(string $token): string
{
    return hash('sha256', $token);
}

function redefinicao_token_valido_formato(string $token): bool
{
    return (bool) preg_match('/^[a-f0-9]{64}$/', $token);
}

function redefinicao_url_base(): string
{
    if (APP_URL !== '') {
        return APP_URL;
    }
    return requisicao_local() ? url_base() : '';
}

function redefinicao_email_disponivel(): bool
{
    return email_configurado() && redefinicao_url_base() !== '';
}

function redefinicao_modo_teste(): bool
{
    return !email_configurado() && depuracao_ativa() && redefinicao_url_base() !== '';
}

function redefinicao_recuperacao_disponivel(): bool
{
    return redefinicao_email_disponivel() || redefinicao_modo_teste();
}

function redefinicao_criar(PDO $conexao, int $usuarioId): string
{
    $agora = date('Y-m-d H:i:s');
    $conexao->prepare('UPDATE redefinicoes_senha SET usado_em = :agora WHERE usuario_id = :usuario_id AND usado_em IS NULL')
            ->execute([':agora' => $agora, ':usuario_id' => $usuarioId]);

    $token = bin2hex(random_bytes(32));
    $conexao->prepare(
        'INSERT INTO redefinicoes_senha (usuario_id, token_hash, criado_em, expira_em)
         VALUES (:usuario_id, :hash, :criado, :expira)'
    )->execute([
        ':usuario_id' => $usuarioId,
        ':hash'       => redefinicao_hash($token),
        ':criado'     => $agora,
        ':expira'     => date('Y-m-d H:i:s', time() + REDEFINICAO_VALIDADE_SEGUNDOS),
    ]);
    return $token;
}

function redefinicao_buscar(PDO $conexao, string $token): ?array
{
    if (!redefinicao_token_valido_formato($token)) {
        return null;
    }
    $consulta = $conexao->prepare(
        'SELECT r.id AS redefinicao_id, r.expira_em, r.usado_em, u.id AS usuario_id, u.nome, u.email, u.telefone, u.status_conta
           FROM redefinicoes_senha r JOIN usuarios u ON u.id = r.usuario_id
          WHERE r.token_hash = :hash LIMIT 1'
    );
    $consulta->execute([':hash' => redefinicao_hash($token)]);
    $linha = $consulta->fetch();

    if (!$linha || $linha['usado_em'] !== null || $linha['expira_em'] < date('Y-m-d H:i:s') || $linha['status_conta'] !== 'Ativa') {
        return null;
    }
    return $linha;
}

function redefinicao_invalidar_todas(PDO $conexao, int $usuarioId): void
{
    $conexao->prepare('UPDATE redefinicoes_senha SET usado_em = :agora WHERE usuario_id = :usuario_id AND usado_em IS NULL')
            ->execute([':agora' => date('Y-m-d H:i:s'), ':usuario_id' => $usuarioId]);
}

function redefinicao_texto_email(string $nome, string $link): string
{
    $minutos = (int) (REDEFINICAO_VALIDADE_SEGUNDOS / 60);
    return "Olá, {$nome}!\n\n"
        . "Recebemos um pedido para criar uma nova senha no Bio Óleo. Para continuar, abra o link abaixo:\n\n"
        . $link . "\n\n"
        . "O link vale por {$minutos} minutos e só pode ser usado uma vez.\n\n"
        . "Se você não pediu isso, pode ignorar este e-mail: a sua senha atual continua a mesma.\n\n"
        . "Bio Óleo";
}
