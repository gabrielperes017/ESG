<?php
declare(strict_types=1);

require __DIR__ . '/config/database.php';
require __DIR__ . '/includes/sessao.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/limite_tentativas.php';
require_once __DIR__ . '/includes/redefinicao_senha.php';

if (usuario_logado() !== null) {
    header('Location: perfil.php');
    exit;
}

$erro = '';
$enviado = ($_GET['enviado'] ?? '') === '1';
$linkDeTeste = '';
if ($enviado && isset($_SESSION['link_teste_redefinicao'])) {
    $linkDeTeste = (string) $_SESSION['link_teste_redefinicao'];
    unset($_SESSION['link_teste_redefinicao']);
}
$disponivel = redefinicao_recuperacao_disponivel();
$email = '';

if ($disponivel && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim((string) ($_POST['email'] ?? ''));

    if (!csrf_valido($_POST['csrf'] ?? null)) {
        $erro = 'A página expirou. Tente novamente.';
    } elseif ($email === '' || strlen($email) > 100 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $erro = 'Informe o e-mail que você usou no cadastro.';
    } else {
        try {
            $conexao        = conectar();
            $enderecoIp     = endereco_ip_do_visitante();
            $emailNormal    = mb_strtolower($email);
            $segundosDeEspera = max(
                limite_segundos_restantes($conexao, 'redefinicao_ip', $enderecoIp),
                limite_segundos_restantes($conexao, 'redefinicao_email', $emailNormal)
            );

            if ($segundosDeEspera > 0) {
                $erro = limite_mensagem($segundosDeEspera);
            } else {
                limite_registrar_falha($conexao, 'redefinicao_ip', $enderecoIp, LIMITE_REDEFINICAO_POR_IP);
                limite_registrar_falha($conexao, 'redefinicao_email', $emailNormal, LIMITE_REDEFINICAO_POR_EMAIL);

                $consulta = $conexao->prepare("SELECT id, nome, email FROM usuarios WHERE email = :email AND status_conta = 'Ativa' LIMIT 1");
                $consulta->execute([':email' => $email]);
                $conta = $consulta->fetch();

                if ($conta) {
                    $token = redefinicao_criar($conexao, (int) $conta['id']);
                    $link  = redefinicao_url_base() . '/redefinir-senha.php?token=' . $token;

                    if (redefinicao_email_disponivel()) {
                        if (!email_enviar((string) $conta['email'], 'Bio Óleo: criar nova senha', redefinicao_texto_email((string) $conta['nome'], $link))) {
                            error_log('Redefinição de senha: o e-mail não saiu (conta ' . (int) $conta['id'] . ').');
                        }
                    } else {
                        $_SESSION['link_teste_redefinicao'] = $link;
                        error_log('Redefinição de senha (teste): link gerado para a conta ' . (int) $conta['id']);
                    }
                }
                header('Location: esqueci-senha.php?enviado=1');
                exit;
            }
        } catch (PDOException $excecao) {
            registrar_erro_banco('Esqueci a senha', $excecao);
            $erro = 'Não foi possível continuar agora. Tente de novo em instantes.';
            if (depuracao_ativa()) {
                $erro .= ' [DEBUG] ' . $excecao->getMessage() . (banco_desatualizado() ? ' ' . BANCO_DESATUALIZADO_AVISO : '');
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="pt-BR">

<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Esqueci minha senha — BioÓleo Digital</title>
  <link rel="stylesheet" href="assets/css/autenticacao.css">
  <link rel="stylesheet" href="assets/css/ajuda.css">
  <link rel="icon" type="image/png" href="assets/img/favicon.png">
</head>

<body>
  <main class="pagina-ajuda pagina-estreita">

    <a class="voltar" href="login.php">&larr; Voltar ao login</a>

    <header class="topo-ajuda">
      <h1>Esqueci minha senha</h1>
      <p>Sem problema. Informe o e-mail do seu cadastro e enviamos um link para você criar uma senha nova.</p>
    </header>

    <?php if ($enviado): ?>
      <div class="alerta alerta-info" role="status">
        <strong>Pronto!</strong> Se existe uma conta com esse e-mail, enviamos o link agora. Confira também a caixa de <strong>spam</strong>. O link vale por 1 hora.
      </div>
      <?php if ($linkDeTeste !== ''): ?>
        <div class="alerta alerta-erro" role="note">
          <strong>[MODO DE TESTE — só no seu computador]</strong> O envio de e-mail não está configurado, então o link aparece aqui:<br>
          <a class="link-teste" href="<?= e($linkDeTeste) ?>"><?= e($linkDeTeste) ?></a>
        </div>
      <?php endif; ?>
    <?php endif; ?>

    <section class="cartao-ajuda">
      <?php if (!$disponivel): ?>
        <h2>Recuperação por e-mail indisponível</h2>
        <p>Neste momento não conseguimos enviar o link por e-mail. Sem problema: a nossa equipe cria uma senha nova com você.</p>
        <p><a class="botao-ajuda" href="ajuda.php?assunto=Acesso#pedido">Pedir ajuda para entrar</a></p>
      <?php else: ?>
        <?php if ($erro !== ''): ?>
          <div class="alerta alerta-erro" role="alert"><?= e($erro) ?></div>
        <?php endif; ?>

        <form class="formulario" method="POST" action="esqueci-senha.php">
          <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
          <div class="grupo-campo">
            <label for="email" class="rotulo-campo"><span>E-mail do cadastro <span class="campo-obrigatorio" aria-hidden="true">*</span></span></label>
            <input type="email" id="email" name="email" class="input-ajuda" value="<?= e($email) ?>" maxlength="100" autocomplete="email" required>
          </div>
          <button type="submit" class="botao-principal" data-enviando="Enviando…"><span>Enviar link</span></button>
        </form>
        <p class="dica">Não tem acesso a esse e-mail? <a class="link-teste" href="ajuda.php?assunto=Acesso#pedido">Peça ajuda: nossa equipe liga para você</a>.</p>
      <?php endif; ?>
    </section>

  </main>
  <script src="assets/js/autenticacao.js"></script>
</body>
</html>
