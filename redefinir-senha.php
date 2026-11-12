<?php
declare(strict_types=1);

require __DIR__ . '/config/database.php';
require __DIR__ . '/includes/sessao.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/limite_tentativas.php';
require_once __DIR__ . '/includes/redefinicao_senha.php';

header('Referrer-Policy: no-referrer');

$token = (string) ($_POST['token'] ?? $_GET['token'] ?? '');
$erro = '';
$linkInvalido = false;
$conta = null;

try {
    $conexao    = conectar();
    $enderecoIp = endereco_ip_do_visitante();
    $segundosDeEspera = limite_segundos_restantes($conexao, 'link_invalido_ip', $enderecoIp);

    if ($segundosDeEspera > 0) {
        $erro = limite_mensagem($segundosDeEspera);
        $linkInvalido = true;
    } else {
        $conta = redefinicao_buscar($conexao, $token);
        if ($conta === null) {
            limite_registrar_falha($conexao, 'link_invalido_ip', $enderecoIp, LIMITE_LINK_INVALIDO_POR_IP);
            $linkInvalido = true;
        }
    }

    if ($conta !== null && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $nova      = (string) ($_POST['senha'] ?? '');
        $confirmar = (string) ($_POST['confirmar_senha'] ?? '');
        $mensagemSenha = mensagem_senha_invalida($nova, (string) $conta['email'], 'A nova senha');

        if (!csrf_valido($_POST['csrf'] ?? null)) {
            $erro = 'A página expirou. Recarregue e tente novamente.';
        } elseif ($mensagemSenha !== null) {
            $erro = $mensagemSenha;
        } elseif ($nova !== $confirmar) {
            $erro = 'A confirmação não é igual à senha.';
        } else {
            $conexao->beginTransaction();
            $gasto = $conexao->prepare('UPDATE redefinicoes_senha SET usado_em = :agora WHERE id = :id AND usado_em IS NULL');
            $gasto->execute([':agora' => date('Y-m-d H:i:s'), ':id' => (int) $conta['redefinicao_id']]);

            if ($gasto->rowCount() !== 1) {
                $conexao->rollBack();
                $linkInvalido = true;
                $conta = null;
            } else {
                $conexao->prepare('UPDATE usuarios SET senha = :senha WHERE id = :usuario_id')
                        ->execute([':senha' => password_hash($nova, PASSWORD_DEFAULT), ':usuario_id' => (int) $conta['usuario_id']]);
                redefinicao_invalidar_todas($conexao, (int) $conta['usuario_id']);
                $conexao->commit();

                limite_zerar($conexao, 'login_conta', (string) $conta['email']);
                $digitosTelefone = telefone_digitos((string) ($conta['telefone'] ?? ''));
                if ($digitosTelefone !== '') {
                    limite_zerar($conexao, 'login_conta', $digitosTelefone);
                }
                limite_zerar($conexao, 'login_ip', $enderecoIp);

                if (email_configurado()) {
                    email_enviar((string) $conta['email'], 'Bio Óleo: sua senha foi alterada',
                        "Olá, {$conta['nome']}!\n\nA senha da sua conta no Bio Óleo acabou de ser alterada.\n\n"
                        . "Se foi você, não precisa fazer nada. Se NÃO foi você, fale com a nossa equipe imediatamente.\n\nBio Óleo");
                }

                session_regenerate_id(true);
                $_SESSION['csrf'] = bin2hex(random_bytes(32));
                header('Location: login.php?senhaalterada=1');
                exit;
            }
        }
    }
} catch (PDOException $excecao) {
    if (isset($conexao) && $conexao->inTransaction()) { $conexao->rollBack(); }
    registrar_erro_banco('Redefinir senha', $excecao);
    $conta = null;
    $erro = 'Não foi possível continuar agora. Tente de novo em instantes.';
    if (depuracao_ativa()) {
        $erro .= ' [DEBUG] ' . $excecao->getMessage() . (banco_desatualizado() ? ' ' . BANCO_DESATUALIZADO_AVISO : '');
    }
}
?>
<!DOCTYPE html>
<html lang="pt-BR">

<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="referrer" content="no-referrer">
  <title>Criar nova senha — BioÓleo Digital</title>
  <link rel="stylesheet" href="assets/css/autenticacao.css">
  <link rel="stylesheet" href="assets/css/ajuda.css">
  <link rel="icon" type="image/png" href="assets/img/favicon.png">
</head>

<body>
  <main class="pagina-ajuda pagina-estreita">

    <a class="voltar" href="login.php">&larr; Voltar ao login</a>

    <header class="topo-ajuda">
      <h1>Criar nova senha</h1>
    </header>

    <?php if ($conta === null): ?>
      <section class="cartao-ajuda">
        <?php if ($erro !== ''): ?><div class="alerta alerta-erro" role="alert"><?= e($erro) ?></div><?php endif; ?>
        <?php if ($linkInvalido && $erro === ''): ?>
          <h2>Este link não vale mais</h2>
          <p>Ele pode ter expirado (vale por 1 hora), já ter sido usado ou estar incompleto. Peça um link novo, é rápido.</p>
        <?php endif; ?>
        <p><a class="botao-ajuda" href="esqueci-senha.php">Pedir um link novo</a></p>
        <p class="dica">Precisa de ajuda? <a class="link-teste" href="ajuda.php?assunto=Acesso#pedido">Nossa equipe liga para você</a>.</p>
      </section>
    <?php else: ?>
      <section class="cartao-ajuda">
        <p>Olá, <strong><?= e($conta['nome']) ?></strong>! Escolha uma senha nova para entrar no sistema.</p>

        <?php if ($erro !== ''): ?><div class="alerta alerta-erro" role="alert"><?= e($erro) ?></div><?php endif; ?>

        <form class="formulario" method="POST" action="redefinir-senha.php">
          <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
          <input type="hidden" name="token" value="<?= e($token) ?>">

          <div class="grupo-campo">
            <label for="senha" class="rotulo-campo"><span>Nova senha (mín. <?= SENHA_MINIMA ?> caracteres) <span class="campo-obrigatorio" aria-hidden="true">*</span></span></label>
            <input type="password" id="senha" name="senha" class="input-ajuda" autocomplete="new-password"
                   minlength="<?= SENHA_MINIMA ?>" maxlength="<?= SENHA_MAXIMA ?>" required>
          </div>
          <div class="grupo-campo">
            <label for="confirmarSenha" class="rotulo-campo"><span>Repita a nova senha <span class="campo-obrigatorio" aria-hidden="true">*</span></span></label>
            <input type="password" id="confirmarSenha" name="confirmar_senha" class="input-ajuda" autocomplete="new-password"
                   minlength="<?= SENHA_MINIMA ?>" maxlength="<?= SENHA_MAXIMA ?>" required>
          </div>

          <button type="submit" class="botao-principal" data-enviando="Salvando…"><span>Salvar nova senha</span></button>
        </form>
      </section>
    <?php endif; ?>

  </main>
  <script src="assets/js/autenticacao.js"></script>
</body>
</html>
