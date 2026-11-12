<?php
declare(strict_types=1);

require __DIR__ . '/config/database.php';
require __DIR__ . '/includes/sessao.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/google.php';
require_once __DIR__ . '/includes/limite_tentativas.php';

const HASH_FALSO = '$2y$10$.Ryue8ONW7YTx2EBP6onMeQ7jcwQoIEm.dv3U8kNH66n2Zzoy7/aa';

if (usuario_logado() !== null) {
    header('Location: dashboard.php');
    exit;
}

$erro = '';
if (isset($_SESSION['erro_login'])) {
    $erro = (string) $_SESSION['erro_login'];
    unset($_SESSION['erro_login']);
}
if (isset($_GET['saiu'])) {
    $aviso = 'Você saiu do sistema.';
} elseif (isset($_GET['expirou'])) {
    $aviso = 'Sua sessão expirou. Entre novamente.';
} elseif (isset($_GET['cadastrado'])) {
    $aviso = 'Conta criada! Entre com o seu e-mail (ou telefone) e a senha que você cadastrou.';
} else {
    $aviso = '';
}
$entrada = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $entrada = trim((string) ($_POST['usuario'] ?? ''));
    $senha   = (string) ($_POST['senha'] ?? '');

    $porEmail = str_contains($entrada, '@');
    $digitos  = $porEmail ? '' : telefone_digitos($entrada);

    if (!csrf_valido($_POST['csrf'] ?? null)) {
        $erro = 'A página expirou. Tente entrar novamente.';
    } elseif ($entrada === '' || $senha === '') {
        $erro = 'Informe o e-mail ou telefone e a senha.';
    } elseif ($porEmail && (strlen($entrada) > 100 || !filter_var($entrada, FILTER_VALIDATE_EMAIL))) {
        $erro = 'Informe um e-mail válido.';
    } elseif (!$porEmail && !in_array(strlen($digitos), [10, 11], true)) {
        $erro = 'Informe um e-mail válido ou um telefone com DDD.';
    } elseif (strlen($senha) > SENHA_MAXIMA) {
        $erro = 'E-mail, telefone ou senha incorretos.';
    } else {
        try {
            $conexao              = conectar();
            $identificadorDaConta = $porEmail ? $entrada : $digitos;
            $ipDoVisitante        = endereco_ip_do_visitante();

            $segundosDeEspera = max(
                limite_segundos_restantes($conexao, 'login_conta', $identificadorDaConta),
                limite_segundos_restantes($conexao, 'login_ip', $ipDoVisitante)
            );

            if ($segundosDeEspera > 0) {
                $erro = limite_mensagem($segundosDeEspera);
            } else {
                $sql = 'SELECT u.id, u.nome, u.email, u.senha, u.perfil_id, u.status_conta, p.nome AS perfil
                          FROM usuarios u
                          JOIN perfis_acesso p ON p.id = u.perfil_id
                         WHERE ';

                if ($porEmail) {
                    $sql       .= 'u.email = :email';
                    $parametros = [':email' => $entrada];
                } else {
                    $sql       .= sql_telefone_digitos('u.telefone') . ' IN (:telefone_sem_pais, :telefone_com_pais)';
                    $parametros = [':telefone_sem_pais' => $digitos, ':telefone_com_pais' => '55' . $digitos];
                }

                $consulta = $conexao->prepare($sql . ' LIMIT 5');
                $consulta->execute($parametros);
                $candidatos = $consulta->fetchAll();

                $usuario = null;
                foreach ($candidatos as $candidato) {
                    if (password_verify($senha, $candidato['senha'])) {
                        $usuario = $candidato;
                        break;
                    }
                }
                if ($candidatos === []) {
                    password_verify($senha, HASH_FALSO);
                }

                if ($usuario === null) {
                    limite_registrar_falha($conexao, 'login_conta', $identificadorDaConta, LIMITE_LOGIN_POR_CONTA);
                    limite_registrar_falha($conexao, 'login_ip', $ipDoVisitante, LIMITE_LOGIN_POR_IP);
                    $erro = 'E-mail, telefone ou senha incorretos.';
                } elseif ($usuario['status_conta'] !== 'Ativa') {
                    $erro = 'Esta conta está inativa. Fale com o administrador.';
                } else {
                    limite_zerar($conexao, 'login_conta', $identificadorDaConta);

                    if (password_needs_rehash($usuario['senha'], PASSWORD_DEFAULT)) {
                        $conexao->prepare('UPDATE usuarios SET senha = :senha WHERE id = :usuario_id')
                                ->execute([':senha' => password_hash($senha, PASSWORD_DEFAULT), ':usuario_id' => (int) $usuario['id']]);
                    }

                    registrar_login($usuario);
                    header('Location: dashboard.php');
                    exit;
                }
            }
        } catch (PDOException $excecao) {
            registrar_erro_banco('Login', $excecao);
            $erro = 'Não foi possível acessar o sistema agora. Tente novamente em instantes.';
            if (depuracao_ativa()) {
                $erro .= ' [DEBUG] ' . $excecao->getMessage();
            }
        }
    }
}

$temFoto = is_file(__DIR__ . '/assets/img/hero_photo.png');
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="description" content="BioÓleo Digital - Plataforma de Gestão Ambiental para coleta e reciclagem de óleo vegetal usado. Acesse sua conta.">
  <title>Login — BioÓleo Digital</title>
  <link rel="stylesheet" href="assets/css/autenticacao.css">
  <link rel="icon" type="image/png" href="assets/img/favicon.png">
</head>
<body>

  <main class="container-login" role="main">

    <aside class="painel-lateral" aria-label="Apresentação Institucional BioÓleo Digital">
      <?php if ($temFoto): ?>
      <img src="assets/img/hero_photo.png" alt="" class="imagem-fundo-lateral" aria-hidden="true">
      <?php endif; ?>

      <div class="cabecalho-lateral">
        <div class="tag-destaque">
          <span class="ponto-tag" aria-hidden="true"></span>
          <span>Plataforma de Gestão Ambiental</span>
        </div>

        <div class="marca-lateral">
          <svg class="icone-marca" viewBox="0 0 24 24" aria-hidden="true">
            <path d="M12 2C8.13 2 5 5.13 5 9c0 5.25 7 13 7 13s7-7.75 7-13c0-3.87-3.13-7-7-7zm0 10.5c-1.93 0-3.5-1.57-3.5-3.5 0-1.29.7-2.42 1.75-3.04.28 1.45 1.48 2.54 2.95 2.54.42 0 .82-.09 1.18-.25-.33 2.45-2.26 4.25-4.38 4.25z"/>
          </svg>
          <h2 class="titulo-marca">Bio Óleo</h2>
        </div>

        <p class="slogan">Reciclando hoje, preservando o amanhã.</p>
      </div>

      <div class="conteudo-lateral">
        <h3 class="titulo-destaque">Gestão inteligente da reciclagem de óleo vegetal.</h3>
        <p class="texto-destaque">
          Conectamos restaurantes e condomínios à logística reversa de forma simples, transparente e sustentável em Cianorte e região.
        </p>

        <div class="lista-beneficios">
          <div class="item-beneficio">
            <svg class="icone-beneficio" viewBox="0 0 24 24" aria-hidden="true">
              <path d="M19 3H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2zm-7 14l-5-5 1.41-1.41L12 14.17l7.59-7.59L21 8l-9 9z"/>
            </svg>
            <span>Agendamento rápido de coletas</span>
          </div>
          <div class="item-beneficio">
            <svg class="icone-beneficio" viewBox="0 0 24 24" aria-hidden="true">
              <path d="M12 2L1 21h22L12 2zm0 3.99L19.53 19H4.47L12 5.99zM11 16h2v2h-2zm0-6h2v4h-2z"/>
            </svg>
            <span>Certificados de destinação ambiental</span>
          </div>
          <div class="item-beneficio">
            <svg class="icone-beneficio" viewBox="0 0 24 24" aria-hidden="true">
              <path d="M12 17.27L18.18 21l-1.64-7.03L22 9.24l-7.19-.61L12 2 9.19 8.63 2 9.24l5.46 4.73L5.82 21z"/>
            </svg>
            <span>Programa de pontos e vantagens</span>
          </div>
        </div>
      </div>

      <div class="rodape-lateral">
        <span>Bio Óleo Cianorte &bull; Solução Tech</span>
        <span>Módulo Web / PWA</span>
      </div>
    </aside>

    <section class="painel-formulario" aria-labelledby="tituloLogin">
      <header class="cabecalho-formulario">
        <div class="logo-mobile">
          <svg viewBox="0 0 24 24" aria-hidden="true">
            <path d="M12 2C8.13 2 5 5.13 5 9c0 5.25 7 13 7 13s7-7.75 7-13c0-3.87-3.13-7-7-7zm0 10.5c-1.93 0-3.5-1.57-3.5-3.5 0-1.29.7-2.42 1.75-3.04.28 1.45 1.48 2.54 2.95 2.54.42 0 .82-.09 1.18-.25-.33 2.45-2.26 4.25-4.38 4.25z"/>
          </svg>
          <span>Bio Óleo Digital</span>
        </div>
        <h1 id="tituloLogin" class="titulo-formulario">Bem-vindo!</h1>
        <p class="subtitulo-formulario">Faça login para continuar.</p>
      </header>

      <?php if ($erro !== ''): ?>
        <div class="alerta alerta-erro" role="alert"><?= e($erro) ?></div>
      <?php elseif ($aviso !== ''): ?>
        <div class="alerta alerta-info" role="status"><?= e($aviso) ?></div>
      <?php endif; ?>

      <form class="formulario" method="POST" action="login.php">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">

        <div class="grupo-campo">
          <label for="email" class="rotulo-campo">
            <span>E-mail ou telefone <span class="campo-obrigatorio" aria-hidden="true">*</span></span>
          </label>
          <div class="campo-com-icone">
            <svg class="icone-campo" viewBox="0 0 24 24" aria-hidden="true">
              <path d="M12 12c2.21 0 4-1.79 4-4s-1.79-4-4-4-4 1.79-4 4 1.79 4 4 4zm0 2c-2.67 0-8 1.34-8 4v2h16v-2c0-2.66-5.33-4-8-4z"/>
            </svg>
            <input
              type="text"
              id="email"
              name="usuario"
              class="input-texto"
              placeholder="Digite seu e-mail ou telefone"
              value="<?= e($entrada) ?>"
              autocomplete="username"
              maxlength="100"
              required
            >
          </div>
        </div>

        <div class="grupo-campo">
          <label for="senha" class="rotulo-campo">
            <span>Senha <span class="campo-obrigatorio" aria-hidden="true">*</span></span>
          </label>
          <div class="campo-com-icone">
            <svg class="icone-campo" viewBox="0 0 24 24" aria-hidden="true">
              <path d="M18 8h-1V6c0-2.76-2.24-5-5-5S7 3.24 7 6v2H6c-1.1 0-2 .9-2 2v10c0 1.1.9 2 2 2h12c1.1 0 2-.9 2-2V10c0-1.1-.9-2-2-2zm-6 9c-1.1 0-2-.9-2-2s.9-2 2-2 2 .9 2 2-.9 2-2 2zm3.1-9H8.9V6c0-1.71 1.39-3.1 3.1-3.1 1.71 0 3.1 1.39 3.1 3.1v2z"/>
            </svg>
            <input
              type="password"
              id="senha"
              name="senha"
              class="input-texto"
              placeholder="Digite sua senha"
              autocomplete="current-password"
              required
            >
          </div>
        </div>

        <div class="opcoes-formulario">
          <label class="rotulo-checkbox">
            <input type="checkbox" id="lembrar" name="lembrar" class="checkbox">
            <span>Lembrar de mim</span>
          </label>
          <a href="ajuda.php?assunto=Senha#pedido" class="link-esqueceu-senha">Esqueceu sua senha?</a>
        </div>

        <button type="submit" class="botao-principal" data-enviando="Entrando…">
          <span>Entrar</span>
        </button>

        <div class="divisor-social" aria-hidden="true">
          <span>ou</span>
        </div>

        <div class="botoes-sociais">
          <?php if (google_configurado()): ?>
          <a class="botao-social" href="google-login.php">
            <svg viewBox="0 0 24 24" aria-hidden="true">
              <path fill="#4285F4" d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92c-.26 1.37-1.04 2.53-2.21 3.31v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.09z"/>
              <path fill="#34A853" d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z"/>
              <path fill="#FBBC05" d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.06H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.94l2.85-2.22.81-.63z"/>
              <path fill="#EA4335" d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.06l3.66 2.84c.87-2.6 3.3-4.52 6.16-4.52z"/>
            </svg>
            <span>Entrar com Google</span>
          </a>
          <?php else: ?>
          <button type="button" class="botao-social" aria-label="Entrar com Google (não configurado)" disabled title="Configure o Google em config/app.php">
            <svg viewBox="0 0 24 24" aria-hidden="true">
              <path fill="#4285F4" d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92c-.26 1.37-1.04 2.53-2.21 3.31v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.09z"/>
              <path fill="#34A853" d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z"/>
              <path fill="#FBBC05" d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.06H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.94l2.85-2.22.81-.63z"/>
              <path fill="#EA4335" d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.06l3.66 2.84c.87-2.6 3.3-4.52 6.16-4.52z"/>
            </svg>
            <span>Entrar com Google</span>
            <span class="etiqueta-breve" aria-hidden="true">não configurado</span>
          </button>
          <?php endif; ?>
        </div>

        <div class="caixa-ajuda">
          <strong>Não consegue entrar ou não tem conta?</strong>
          <span>Veja o passo a passo ou peça para a nossa equipe ligar para você.</span>
          <a class="botao-ajuda" href="ajuda.php?assunto=Acesso">Preciso de ajuda</a>
        </div>

        <footer class="rodape-formulario">
          <span>Ainda não tem uma conta? </span>
          <a href="cadastro.php">Criar conta</a>
        </footer>

      </form>
    </section>

  </main>

  <script src="assets/js/autenticacao.js"></script>
  <script src="assets/js/mostrar-senha.js"></script>
</body>
</html>
