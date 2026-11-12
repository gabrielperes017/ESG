<?php
declare(strict_types=1);

require __DIR__ . '/config/database.php';
require __DIR__ . '/includes/sessao.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/google.php';
require_once __DIR__ . '/includes/endereco.php';

if (usuario_logado() !== null) {
    header('Location: dashboard.php');
    exit;
}

$tipos = [
    'Restaurante' => 'Restaurante / Lanchonete',
    'Condominio'  => 'Condomínio Residencial',
    'Escola'      => 'Escola / Instituição de Ensino',
    'Empresa'     => 'Indústria / Empresa',
    'Residencial' => 'Pessoa Física / Residencial',
];

$erro = '';
$form = ['nome' => '', 'email' => '', 'telefone' => '', 'tipo' => ''] + endereco_vazio();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $form['nome']     = trim((string) ($_POST['nome'] ?? ''));
    $form['email']    = trim((string) ($_POST['email'] ?? ''));
    $form['telefone'] = trim((string) ($_POST['telefone'] ?? ''));
    $form['tipo']     = (string) ($_POST['tipo'] ?? '');
    $endereco         = endereco_do_formulario($_POST);
    $form             = array_merge($form, $endereco);
    $senha            = (string) ($_POST['senha'] ?? '');
    $confirmar        = (string) ($_POST['confirmar_senha'] ?? '');

    $digitos = telefone_digitos($form['telefone']);
    $mensagemSenha = mensagem_senha_invalida($senha, $form['email']);

    if (!csrf_valido($_POST['csrf'] ?? null)) {
        $erro = 'A página expirou. Tente novamente.';
    } elseif ($form['nome'] === '' || mb_strlen($form['nome']) > 150) {
        $erro = 'Informe seu nome completo (até 150 caracteres).';
    } elseif (strlen($form['email']) > 100 || !filter_var($form['email'], FILTER_VALIDATE_EMAIL)) {
        $erro = 'Informe um e-mail válido.';
    } elseif (!preg_match('/^[0-9()+\-\s]{8,20}$/', $form['telefone']) || !in_array(strlen($digitos), [10, 11], true)) {
        $erro = 'Informe um telefone válido, com DDD. Exemplo: (44) 99999-0000.';
    } elseif (!isset($tipos[$form['tipo']])) {
        $erro = 'Escolha o seu tipo de perfil.';
    } elseif (($erroEndereco = endereco_mensagem_invalida($endereco)) !== null) {
        $erro = $erroEndereco;
    } elseif ($mensagemSenha !== null) {
        $erro = $mensagemSenha;
    } elseif ($senha !== $confirmar) {
        $erro = 'A confirmação não é igual à senha.';
    } else {
        try {
            $pdo = conectar();

            $consulta = $pdo->prepare("SELECT id FROM perfis_acesso WHERE nome = 'Cliente' LIMIT 1");
            $consulta->execute();
            $perfilCliente = $consulta->fetchColumn();

            if ($perfilCliente === false) {
                error_log('Cadastro: perfil "Cliente" não existe em perfis_acesso.');
                $erro = 'Não foi possível criar a conta agora. Tente novamente em instantes.';
            } else {
                $consulta = $pdo->prepare('SELECT COUNT(*) FROM usuarios WHERE email = :e');
                $consulta->execute([':e' => $form['email']]);
                $emailExiste = (int) $consulta->fetchColumn() > 0;

                $consulta = $pdo->prepare(
                    'SELECT COUNT(*) FROM usuarios WHERE ' . sql_telefone_digitos('telefone') . ' IN (:t1, :t2)'
                );
                $consulta->execute([':t1' => $digitos, ':t2' => '55' . $digitos]);
                $telefoneExiste = (int) $consulta->fetchColumn() > 0;

                if ($emailExiste) {
                    $erro = 'Já existe uma conta com esse e-mail. Use "Entrar" ou informe outro e-mail.';
                } elseif ($telefoneExiste) {
                    $erro = 'Já existe uma conta com esse telefone. Use "Entrar" ou informe outro telefone.';
                } else {
                    $pdo->prepare(
                        'INSERT INTO usuarios (nome, email, senha, perfil_id, telefone, cargo, data_cadastro,
                                               cep, logradouro, numero, complemento, bairro, cidade, uf, ponto_referencia)
                         VALUES (:n, :e, :s, :p, :t, :c, :d,
                                 :cep, :logradouro, :numero, :complemento, :bairro, :cidade, :uf, :referencia)'
                    )->execute([
                        ':n' => $form['nome'],
                        ':e' => $form['email'],
                        ':s' => password_hash($senha, PASSWORD_DEFAULT),
                        ':p' => (int) $perfilCliente,
                        ':t' => telefone_formatado($digitos),
                        ':c' => $tipos[$form['tipo']],
                        ':d' => date('Y-m-d'),
                        ':cep'         => $endereco['cep'],
                        ':logradouro'  => $endereco['logradouro'],
                        ':numero'      => $endereco['numero'],
                        ':complemento' => $endereco['complemento'] !== '' ? $endereco['complemento'] : null,
                        ':bairro'      => $endereco['bairro'],
                        ':cidade'      => $endereco['cidade'],
                        ':uf'          => $endereco['uf'],
                        ':referencia'  => $endereco['ponto_referencia'] !== '' ? $endereco['ponto_referencia'] : null,
                    ]);

                    unset($_SESSION['csrf']);
                    header('Location: login.php?cadastrado=1');
                    exit;
                }
            }
        } catch (PDOException $excecao) {
            registrar_erro_banco('Cadastro', $excecao);
            if ($excecao->getCode() === '23000') {
                $erro = 'Já existe uma conta com esse e-mail. Use "Entrar" ou informe outro e-mail.';
            } else {
                $erro = 'Não foi possível criar a conta agora. Tente novamente em instantes.';
                if (depuracao_ativa()) {
                    $erro .= ' [DEBUG] ' . $excecao->getMessage();
                }
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
  <meta name="description"
    content="BioÓleo Digital - Cadastre seu restaurante ou condomínio para solicitar coletas de óleo vegetal e emitir certificados ambientais.">
  <title>Criar Conta — BioÓleo Digital</title>

  <link rel="stylesheet" href="assets/css/autenticacao.css">
  <link rel="icon" type="image/png" href="assets/img/favicon.png">
</head>

<body>

  <main class="container-cadastro" role="main">

    <aside class="painel-lateral" aria-label="Apresentação do Programa de Coleta BioÓleo">
      <?php if ($temFoto): ?>
      <img src="assets/img/hero_photo.png" alt="" class="imagem-fundo-lateral" aria-hidden="true">
      <?php endif; ?>

      <div class="cabecalho-lateral">
        <div class="tag-destaque">
          <span class="ponto-tag" aria-hidden="true"></span>
          <span>Junte-se ao BioÓleo</span>
        </div>

        <div class="marca-lateral">
          <svg class="icone-marca" viewBox="0 0 24 24" aria-hidden="true">
            <path
              d="M12 2C8.13 2 5 5.13 5 9c0 5.25 7 13 7 13s7-7.75 7-13c0-3.87-3.13-7-7-7zm0 10.5c-1.93 0-3.5-1.57-3.5-3.5 0-1.29.7-2.42 1.75-3.04.28 1.45 1.48 2.54 2.95 2.54.42 0 .82-.09 1.18-.25-.33 2.45-2.26 4.25-4.38 4.25z" />
          </svg>
          <h2 class="titulo-marca">Bio Óleo</h2>
        </div>

        <p class="slogan">Reciclando hoje, preservando o amanhã.</p>
      </div>

      <div class="conteudo-lateral">
        <h3 class="titulo-destaque">Faça parte da rede de descarte ecológico.</h3>
        <p class="texto-destaque">
          Cadastre seu estabelecimento ou residência para solicitar bombonas, agendar coletas e receber certificados de
          conformidade ambiental.
        </p>
      </div>

      <div class="rodape-lateral">
        <span>Bio Óleo Cianorte &bull; Solução Tech</span>
        <span>Módulo Web / PWA</span>
      </div>
    </aside>

    <section class="painel-formulario" aria-labelledby="tituloCadastro">

      <a href="login.php" class="link-voltar" title="Voltar para a tela de login">
        <svg viewBox="0 0 24 24" aria-hidden="true">
          <path d="M20 11H7.83l5.59-5.59L12 4l-8 8 8 8 1.41-1.41L7.83 13H20v-2z" />
        </svg>
        <span>Voltar ao login</span>
      </a>

      <header class="cabecalho-formulario">
        <div class="logo-mobile">
          <svg viewBox="0 0 24 24" aria-hidden="true">
            <path
              d="M12 2C8.13 2 5 5.13 5 9c0 5.25 7 13 7 13s7-7.75 7-13c0-3.87-3.13-7-7-7zm0 10.5c-1.93 0-3.5-1.57-3.5-3.5 0-1.29.7-2.42 1.75-3.04.28 1.45 1.48 2.54 2.95 2.54.42 0 .82-.09 1.18-.25-.33 2.45-2.26 4.25-4.38 4.25z" />
          </svg>
          <span>Bio Óleo Digital</span>
        </div>
        <h1 id="tituloCadastro" class="titulo-formulario">Criar conta</h1>
        <p class="subtitulo-formulario">Preencha seus dados para começar.</p>
      </header>

      <?php if ($erro !== ''): ?>
        <div class="alerta alerta-erro" role="alert"><?= e($erro) ?></div>
      <?php endif; ?>

      <form class="formulario" method="POST" action="cadastro.php">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">

        <div class="grupo-campo">
          <label for="nome" class="rotulo-campo">
            <span>Nome completo <span class="campo-obrigatorio" aria-hidden="true">*</span></span>
          </label>
          <div class="campo-com-icone">
            <svg class="icone-campo" viewBox="0 0 24 24" aria-hidden="true">
              <path
                d="M12 12c2.21 0 4-1.79 4-4s-1.79-4-4-4-4 1.79-4 4 1.79 4 4 4zm0 2c-2.67 0-8 1.34-8 4v2h16v-2c0-2.66-5.33-4-8-4z" />
            </svg>
            <input type="text" id="nome" name="nome" class="input-texto"
              placeholder="Nome completo ou razão social" value="<?= e($form['nome']) ?>"
              autocomplete="name" maxlength="150" required>
          </div>
        </div>

        <div class="linha-dupla">

          <div class="grupo-campo">
            <label for="email" class="rotulo-campo">
              <span>E-mail <span class="campo-obrigatorio" aria-hidden="true">*</span></span>
            </label>
            <div class="campo-com-icone">
              <svg class="icone-campo" viewBox="0 0 24 24" aria-hidden="true">
                <path
                  d="M20 4H4c-1.1 0-1.99.9-1.99 2L2 18c0 1.1.9 2 2 2h16c1.1 0 2-.9 2-2V6c0-1.1-.9-2-2-2zm0 4l-8 5-8-5V6l8 5 8-5v2z" />
              </svg>
              <input type="email" id="email" name="email" class="input-texto" placeholder="Digite seu e-mail"
                value="<?= e($form['email']) ?>" autocomplete="email" maxlength="100" required>
            </div>
          </div>

          <div class="grupo-campo">
            <label for="telefone" class="rotulo-campo">
              <span>Telefone <span class="campo-obrigatorio" aria-hidden="true">*</span></span>
            </label>
            <div class="campo-com-icone">
              <svg class="icone-campo" viewBox="0 0 24 24" aria-hidden="true">
                <path
                  d="M6.62 10.79c1.44 2.83 3.76 5.14 6.59 6.59l2.2-2.2c.27-.27.67-.36 1.02-.24 1.12.37 2.33.57 3.57.57.55 0 1 .45 1 1V20c0 .55-.45 1-1 1-9.39 0-17-7.61-17-17 0-.55.45-1 1-1h3.5c.55 0 1 .45 1 1 0 1.25.2 2.45.57 3.57.11.35.03.74-.25 1.02l-2.2 2.2z" />
              </svg>
              <input type="tel" id="telefone" name="telefone" class="input-texto" placeholder="(00) 00000-0000"
                value="<?= e($form['telefone']) ?>" autocomplete="tel" inputmode="tel" maxlength="20" required>
            </div>
          </div>

        </div>

        <div class="grupo-campo">
          <label for="tipo" class="rotulo-campo">
            <span>Tipo de perfil <span class="campo-obrigatorio" aria-hidden="true">*</span></span>
          </label>
          <div class="campo-com-icone">
            <svg class="icone-campo" viewBox="0 0 24 24" aria-hidden="true">
              <path
                d="M12 7V3H2v18h20V7H12zM6 19H4v-2h2v2zm0-4H4v-2h2v2zm0-4H4V9h2v2zm0-4H4V5h2v2zm4 12H8v-2h2v2zm0-4H8v-2h2v2zm0-4H8V9h2v2zm0-4H8V5h2v2zm10 12h-8v-2h2v-2h-2v-2h2v-2h-2V9h8v10zm-2-8h-2v2h2v-2zm0 4h-2v2h2v-2z" />
            </svg>
            <select id="tipo" name="tipo" class="select-perfil" required>
              <option value="" disabled <?= $form['tipo'] === '' ? 'selected' : '' ?>>Selecione seu perfil</option>
              <?php foreach ($tipos as $valor => $rotulo): ?>
                <option value="<?= e($valor) ?>" <?= $form['tipo'] === $valor ? 'selected' : '' ?>><?= e($rotulo) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>

        <fieldset class="bloco-endereco">
          <legend>Endereço de coleta <span class="campo-obrigatorio" aria-hidden="true">*</span></legend>
          <p class="aviso-endereco">É neste endereço que nosso motorista vai retirar o óleo. Confira com atenção: um dado errado atrasa a coleta.</p>

          <div class="linha-dupla">
            <div class="grupo-campo">
              <label for="cep" class="rotulo-campo"><span>CEP <span class="campo-obrigatorio" aria-hidden="true">*</span></span></label>
              <div class="campo-com-icone">
                <svg class="icone-campo" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 2C8.13 2 5 5.13 5 9c0 5.25 7 13 7 13s7-7.75 7-13c0-3.87-3.13-7-7-7zm0 9.5a2.5 2.5 0 0 1 0-5 2.5 2.5 0 0 1 0 5z" /></svg>
                <input type="text" id="cep" name="cep" class="input-texto" placeholder="00000-000"
                  value="<?= e($form['cep'] !== '' ? endereco_cep_formatado($form['cep']) : '') ?>"
                  autocomplete="postal-code" inputmode="numeric" maxlength="9" data-mascara="cep" required>
              </div>
            </div>
            <div class="grupo-campo">
              <label for="cidade" class="rotulo-campo"><span>Cidade <span class="campo-obrigatorio" aria-hidden="true">*</span></span></label>
              <div class="campo-com-icone">
                <svg class="icone-campo" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 2C8.13 2 5 5.13 5 9c0 5.25 7 13 7 13s7-7.75 7-13c0-3.87-3.13-7-7-7zm0 9.5a2.5 2.5 0 0 1 0-5 2.5 2.5 0 0 1 0 5z" /></svg>
                <input type="text" id="cidade" name="cidade" class="input-texto" placeholder="Ex.: Cianorte"
                  value="<?= e($form['cidade']) ?>" autocomplete="address-level2" maxlength="80" required>
              </div>
            </div>
          </div>

          <div class="linha-dupla linha-rua">
            <div class="grupo-campo">
              <label for="logradouro" class="rotulo-campo"><span>Rua / Avenida <span class="campo-obrigatorio" aria-hidden="true">*</span></span></label>
              <div class="campo-com-icone">
                <svg class="icone-campo" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 2C8.13 2 5 5.13 5 9c0 5.25 7 13 7 13s7-7.75 7-13c0-3.87-3.13-7-7-7zm0 9.5a2.5 2.5 0 0 1 0-5 2.5 2.5 0 0 1 0 5z" /></svg>
                <input type="text" id="logradouro" name="logradouro" class="input-texto" placeholder="Ex.: Av. Paraná"
                  value="<?= e($form['logradouro']) ?>" autocomplete="address-line1" maxlength="150" required>
              </div>
            </div>
            <div class="grupo-campo">
              <label for="numero" class="rotulo-campo"><span>Número <span class="campo-obrigatorio" aria-hidden="true">*</span></span></label>
              <div class="campo-com-icone">
                <svg class="icone-campo" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 2C8.13 2 5 5.13 5 9c0 5.25 7 13 7 13s7-7.75 7-13c0-3.87-3.13-7-7-7zm0 9.5a2.5 2.5 0 0 1 0-5 2.5 2.5 0 0 1 0 5z" /></svg>
                <input type="text" id="numero" name="numero" class="input-texto" placeholder="Ex.: 120 ou S/N"
                  value="<?= e($form['numero']) ?>" maxlength="20" required>
              </div>
            </div>
          </div>

          <div class="linha-dupla">
            <div class="grupo-campo">
              <label for="bairro" class="rotulo-campo"><span>Bairro <span class="campo-obrigatorio" aria-hidden="true">*</span></span></label>
              <div class="campo-com-icone">
                <svg class="icone-campo" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 2C8.13 2 5 5.13 5 9c0 5.25 7 13 7 13s7-7.75 7-13c0-3.87-3.13-7-7-7zm0 9.5a2.5 2.5 0 0 1 0-5 2.5 2.5 0 0 1 0 5z" /></svg>
                <input type="text" id="bairro" name="bairro" class="input-texto" placeholder="Ex.: Centro"
                  value="<?= e($form['bairro']) ?>" autocomplete="address-level3" maxlength="80" required>
              </div>
            </div>
            <div class="grupo-campo">
              <label for="uf" class="rotulo-campo"><span>Estado <span class="campo-obrigatorio" aria-hidden="true">*</span></span></label>
              <div class="campo-com-icone">
                <svg class="icone-campo" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 2C8.13 2 5 5.13 5 9c0 5.25 7 13 7 13s7-7.75 7-13c0-3.87-3.13-7-7-7zm0 9.5a2.5 2.5 0 0 1 0-5 2.5 2.5 0 0 1 0 5z" /></svg>
                <select id="uf" name="uf" class="select-perfil" autocomplete="address-level1" required>
                  <option value="" disabled <?= $form['uf'] === '' ? 'selected' : '' ?>>Selecione</option>
                  <?php foreach (ENDERECO_ESTADOS as $sigla => $nomeEstado): ?>
                    <option value="<?= e($sigla) ?>" <?= $form['uf'] === $sigla ? 'selected' : '' ?>><?= e($sigla . ' — ' . $nomeEstado) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
            </div>
          </div>

          <div class="linha-dupla">
            <div class="grupo-campo">
              <label for="complemento" class="rotulo-campo"><span>Complemento</span></label>
              <div class="campo-com-icone">
                <svg class="icone-campo" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 2C8.13 2 5 5.13 5 9c0 5.25 7 13 7 13s7-7.75 7-13c0-3.87-3.13-7-7-7zm0 9.5a2.5 2.5 0 0 1 0-5 2.5 2.5 0 0 1 0 5z" /></svg>
                <input type="text" id="complemento" name="complemento" class="input-texto" placeholder="Bloco, sala, apto (opcional)"
                  value="<?= e($form['complemento']) ?>" autocomplete="address-line2" maxlength="80">
              </div>
            </div>
            <div class="grupo-campo">
              <label for="ponto_referencia" class="rotulo-campo"><span>Ponto de referência</span></label>
              <div class="campo-com-icone">
                <svg class="icone-campo" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 2C8.13 2 5 5.13 5 9c0 5.25 7 13 7 13s7-7.75 7-13c0-3.87-3.13-7-7-7zm0 9.5a2.5 2.5 0 0 1 0-5 2.5 2.5 0 0 1 0 5z" /></svg>
                <input type="text" id="ponto_referencia" name="ponto_referencia" class="input-texto" placeholder="Ex.: ao lado da padaria (opcional)"
                  value="<?= e($form['ponto_referencia']) ?>" maxlength="150">
              </div>
            </div>
          </div>
        </fieldset>

        <div class="linha-dupla">

          <div class="grupo-campo">
            <label for="senha" class="rotulo-campo">
              <span>Senha <span class="campo-obrigatorio" aria-hidden="true">*</span></span>
            </label>
            <div class="campo-com-icone">
              <svg class="icone-campo" viewBox="0 0 24 24" aria-hidden="true">
                <path
                  d="M18 8h-1V6c0-2.76-2.24-5-5-5S7 3.24 7 6v2H6c-1.1 0-2 .9-2 2v10c0 1.1.9 2 2 2h12c1.1 0 2-.9 2-2V10c0-1.1-.9-2-2-2zm-6 9c-1.1 0-2-.9-2-2s.9-2 2-2 2 .9 2 2-.9 2-2 2zm3.1-9H8.9V6c0-1.71 1.39-3.1 3.1-3.1 1.71 0 3.1 1.39 3.1 3.1v2z" />
              </svg>
              <input type="password" id="senha" name="senha" class="input-texto"
                placeholder="Crie uma senha (mín. <?= SENHA_MINIMA ?>)" autocomplete="new-password"
                minlength="<?= SENHA_MINIMA ?>" maxlength="<?= SENHA_MAXIMA ?>" required>
            </div>
          </div>

          <div class="grupo-campo">
            <label for="confirmarSenha" class="rotulo-campo">
              <span>Confirmar senha <span class="campo-obrigatorio" aria-hidden="true">*</span></span>
            </label>
            <div class="campo-com-icone">
              <svg class="icone-campo" viewBox="0 0 24 24" aria-hidden="true">
                <path
                  d="M18 8h-1V6c0-2.76-2.24-5-5-5S7 3.24 7 6v2H6c-1.1 0-2 .9-2 2v10c0 1.1.9 2 2 2h12c1.1 0 2-.9 2-2V10c0-1.1-.9-2-2-2zm-6 9c-1.1 0-2-.9-2-2s.9-2 2-2 2 .9 2 2-.9 2-2 2zm3.1-9H8.9V6c0-1.71 1.39-3.1 3.1-3.1 1.71 0 3.1 1.39 3.1 3.1v2z" />
              </svg>
              <input type="password" id="confirmarSenha" name="confirmar_senha" class="input-texto"
                placeholder="Repita sua senha" autocomplete="new-password"
                minlength="<?= SENHA_MINIMA ?>" maxlength="<?= SENHA_MAXIMA ?>" required>
            </div>
          </div>

        </div>

        <button type="submit" class="botao-principal" data-enviando="Cadastrando…">
          <span>Cadastrar</span>
        </button>

        <?php if (google_configurado()): ?>
        <div class="divisor-social" aria-hidden="true"><span>ou</span></div>
        <div class="botoes-sociais">
          <a class="botao-social" href="google-login.php">
            <svg viewBox="0 0 24 24" aria-hidden="true">
              <path fill="#4285F4" d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92c-.26 1.37-1.04 2.53-2.21 3.31v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.09z"/>
              <path fill="#34A853" d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z"/>
              <path fill="#FBBC05" d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.06H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.94l2.85-2.22.81-.63z"/>
              <path fill="#EA4335" d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.06l3.66 2.84c.87-2.6 3.3-4.52 6.16-4.52z"/>
            </svg>
            <span>Cadastrar com Google</span>
          </a>
        </div>
        <?php endif; ?>

        <div class="caixa-ajuda">
          <strong>Está difícil se cadastrar?</strong>
          <span>Veja o passo a passo ou peça para a nossa equipe ligar para você.</span>
          <a class="botao-ajuda" href="ajuda.php?assunto=Cadastro">Preciso de ajuda</a>
        </div>

        <footer class="rodape-formulario">
          <span>Já tem uma conta? </span>
          <a href="login.php">Entrar</a>
        </footer>

      </form>
    </section>

  </main>

  <script src="assets/js/autenticacao.js"></script>
  <script src="assets/js/mostrar-senha.js"></script>
  <script src="assets/js/endereco.js" defer></script>
</body>

</html>
