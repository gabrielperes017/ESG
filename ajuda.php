<?php
declare(strict_types=1);

require __DIR__ . '/config/database.php';
require __DIR__ . '/includes/sessao.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/limite_tentativas.php';
require_once __DIR__ . '/includes/ajuda.php';

$usuario = usuario_logado();
$voltarPara = $usuario !== null ? 'dashboard.php' : 'login.php';
$voltarRotulo = $usuario !== null ? 'Voltar ao sistema' : 'Voltar ao login';

$erro = '';
$enviado = ($_GET['enviado'] ?? '') === '1';
$assuntoDaUrl = (string) ($_GET['assunto'] ?? '');
$pedido = [
    'nome'     => $usuario['nome'] ?? '',
    'telefone' => '',
    'email'    => '',
    'assunto'  => isset(AJUDA_ASSUNTOS[$assuntoDaUrl]) ? $assuntoDaUrl : '',
    'mensagem' => '',
];

try {
    $pdo = conectar();

    if ($usuario !== null && $_SERVER['REQUEST_METHOD'] !== 'POST') {
        $consulta = $pdo->prepare('SELECT telefone FROM usuarios WHERE id = :id');
        $consulta->execute([':id' => $usuario['id']]);
        $pedido['telefone'] = (string) ($consulta->fetchColumn() ?: '');
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $pedido = ajuda_ler_formulario($_POST);
        $enderecoIp = endereco_ip_do_visitante();
        $segundosDeEspera = limite_segundos_restantes($pdo, 'pedido_ajuda_ip', $enderecoIp);

        if (!csrf_valido($_POST['csrf'] ?? null)) {
            $erro = 'A página expirou. Tente novamente.';
        } elseif (trim((string) ($_POST['site'] ?? '')) !== '') {
            header('Location: ajuda.php?enviado=1');
            exit;
        } elseif ($segundosDeEspera > 0) {
            $erro = limite_mensagem($segundosDeEspera);
        } elseif (($erroPedido = ajuda_mensagem_invalida($pedido)) !== null) {
            $erro = $erroPedido;
        } else {
            limite_registrar_falha($pdo, 'pedido_ajuda_ip', $enderecoIp, LIMITE_PEDIDOS_AJUDA_POR_IP);
            $pdo->prepare(
                'INSERT INTO pedidos_ajuda (usuario_id, nome, telefone, email, assunto, mensagem)
                 VALUES (:usuario, :nome, :telefone, :email, :assunto, :mensagem)'
            )->execute([
                ':usuario'  => $usuario['id'] ?? null,
                ':nome'     => $pedido['nome'],
                ':telefone' => telefone_formatado(telefone_digitos($pedido['telefone'])),
                ':email'    => $pedido['email'] !== '' ? $pedido['email'] : null,
                ':assunto'  => $pedido['assunto'],
                ':mensagem' => $pedido['mensagem'] !== '' ? $pedido['mensagem'] : null,
            ]);
            header('Location: ajuda.php?enviado=1');
            exit;
        }
    }
} catch (PDOException $excecao) {
    registrar_erro_banco('Ajuda', $excecao);
    $erro = 'Não foi possível enviar agora. Tente de novo em instantes ou use o WhatsApp/telefone abaixo.';
    if (depuracao_ativa()) {
        $erro .= ' [DEBUG] ' . $excecao->getMessage();
        if (stripos($excecao->getMessage(), 'assunto') !== false) {
            $erro .= ' Falta rodar o arquivo banco/atualizacao_v14.sql no MySQL.';
        }
    }
}

$linkWhatsapp = ajuda_link_whatsapp();
$telefoneContato = ajuda_telefone_exibicao();
?>
<!DOCTYPE html>
<html lang="pt-BR">

<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Ajuda — BioÓleo Digital</title>
  <link rel="stylesheet" href="assets/css/autenticacao.css">
  <link rel="stylesheet" href="assets/css/ajuda.css">
  <link rel="icon" type="image/png" href="assets/img/favicon.png">
</head>

<body>
  <main class="pagina-ajuda">

    <a class="voltar" href="<?= e($voltarPara) ?>">&larr; <?= e($voltarRotulo) ?></a>

    <header class="topo-ajuda">
      <h1>Precisa de ajuda?</h1>
      <p>Sem problema! Veja o passo a passo abaixo. Se ainda assim não der certo, deixe seu telefone que <strong>a nossa equipe liga para você</strong> e resolve junto.</p>
    </header>

    <?php if ($enviado): ?>
      <div class="alerta alerta-info" role="status">
        <strong>Pedido recebido!</strong> Nossa equipe vai ligar para o telefone que você informou. Você não precisa fazer mais nada.
      </div>
    <?php endif; ?>

    <?php if ($linkWhatsapp !== '' || $telefoneContato !== ''): ?>
    <section class="cartao-ajuda contato-rapido" aria-label="Falar com a equipe agora">
      <h2>Prefere falar agora?</h2>
      <div class="botoes-contato">
        <?php if ($linkWhatsapp !== ''): ?>
          <a class="botao-contato botao-whatsapp" href="<?= e($linkWhatsapp) ?>" target="_blank" rel="noopener noreferrer">Chamar no WhatsApp</a>
        <?php endif; ?>
        <?php if ($telefoneContato !== ''): ?>
          <a class="botao-contato" href="tel:<?= e(preg_replace('/[^0-9+]/', '', $telefoneContato) ?? '') ?>">Ligar: <?= e($telefoneContato) ?></a>
        <?php endif; ?>
      </div>
    </section>
    <?php endif; ?>

    <section class="cartao-ajuda" aria-labelledby="tituloPasso">
      <h2 id="tituloPasso">Passo a passo</h2>

      <details>
        <summary>Como criar a minha conta</summary>
        <ol>
          <li>Na tela de entrada, toque em <strong>Criar conta</strong>.</li>
          <li>Escreva seu <strong>nome</strong>, <strong>e-mail</strong> e <strong>telefone com DDD</strong>.</li>
          <li>Escolha o seu <strong>tipo de perfil</strong> (restaurante, condomínio, casa etc.).</li>
          <li>Preencha o <strong>endereço onde vamos buscar o óleo</strong>: CEP, rua, número, bairro, cidade e estado. Se a casa não tem número, escreva <strong>S/N</strong>.</li>
          <li>Crie uma <strong>senha</strong> com pelo menos 8 letras ou números e repita ela.</li>
          <li>Toque em <strong>Cadastrar</strong>. Pronto! Agora é só entrar com o e-mail (ou telefone) e a senha.</li>
        </ol>
        <p class="dica">Se você tem uma conta Google, pode tocar em <strong>Cadastrar com Google</strong>. Depois a gente só pede o seu endereço.</p>
      </details>

      <details>
        <summary>Como pedir uma coleta de óleo</summary>
        <ol>
          <li>Entre no sistema e escolha <strong>Solicitar coleta</strong> no menu.</li>
          <li>Confira o <strong>local da coleta</strong> (é o endereço do seu cadastro). Se estiver errado, toque em corrigir.</li>
          <li>Diga <strong>quantos litros</strong> de óleo você tem (pode ser aproximado) e escolha uma <strong>data</strong> que seja boa para você.</li>
          <li>Toque em <strong>Enviar solicitação</strong>. A equipe confirma a data e você acompanha em <strong>Acompanhar coleta</strong>.</li>
        </ol>
      </details>

      <details>
        <summary>Como guardar o óleo até a coleta</summary>
        <ul>
          <li>Espere o óleo <strong>esfriar</strong> antes de guardar.</li>
          <li>Use <strong>garrafas PET bem fechadas</strong>.</li>
          <li>Não misture com <strong>água</strong> nem com restos de comida. Se quiser, passe numa peneira antes.</li>
          <li>Guarde em lugar fresco, longe de crianças e animais.</li>
        </ul>
      </details>

      <details>
        <summary>Não consigo entrar na minha conta</summary>
        <ul>
          <li>Você pode entrar com o <strong>e-mail</strong> ou com o <strong>telefone</strong> cadastrado.</li>
          <li>Confira se a senha não está com letras maiúsculas por engano.</li>
          <li>Depois de várias tentativas erradas, o sistema espera uns <strong>15 minutos</strong> por segurança. Aguarde e tente de novo.</li>
          <li>Esqueceu a senha? Use o formulário abaixo, escolha <strong>“Esqueci minha senha”</strong> e informe o <strong>e-mail do cadastro</strong>. A nossa equipe liga para você, confirma que é você mesmo e cria uma senha nova.</li>
          <li>Não lembra nem do e-mail? Escolha <strong>“Não consigo entrar na minha conta”</strong> no formulário: a equipe ajuda você.</li>
        </ul>
      </details>

      <details>
        <summary>O que são pontos e certificados?</summary>
        <p>A cada litro de óleo entregue para reciclagem você ganha <strong>1 ponto</strong>, que pode ser trocado por vantagens na tela <strong>Pontos e vantagens</strong>. Depois de cada coleta concluída, você pode abrir e imprimir o seu <strong>certificado</strong> de descarte correto, na tela <strong>Certificados</strong>.</p>
      </details>
    </section>

    <section class="cartao-ajuda" aria-labelledby="tituloPedido" id="pedido">
      <h2 id="tituloPedido">Ainda precisa de ajuda? A gente liga para você</h2>
      <?php if ($pedido['assunto'] === 'Senha'): ?>
        <p class="dica"><strong>Esqueceu a senha?</strong> Informe o seu telefone e o e-mail do cadastro. A nossa equipe liga para você, confirma que é você mesmo e cria uma senha nova.</p>
      <?php endif; ?>

      <?php if ($erro !== ''): ?>
        <div class="alerta alerta-erro" role="alert"><?= e($erro) ?></div>
      <?php endif; ?>

      <form class="formulario" method="POST" action="ajuda.php#pedido">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">

        <div class="armadilha" aria-hidden="true">
          <label for="site">Não preencha este campo</label>
          <input type="text" id="site" name="site" tabindex="-1" autocomplete="off">
        </div>

        <div class="linha-dupla">
          <div class="grupo-campo">
            <label for="nome" class="rotulo-campo"><span>Seu nome <span class="campo-obrigatorio" aria-hidden="true">*</span></span></label>
            <input type="text" id="nome" name="nome" class="input-ajuda" value="<?= e($pedido['nome']) ?>" maxlength="150" autocomplete="name" required>
          </div>
          <div class="grupo-campo">
            <label for="telefone" class="rotulo-campo"><span>Telefone com DDD <span class="campo-obrigatorio" aria-hidden="true">*</span></span></label>
            <input type="tel" id="telefone" name="telefone" class="input-ajuda" placeholder="(00) 00000-0000" value="<?= e($pedido['telefone']) ?>" maxlength="20" autocomplete="tel" inputmode="tel" required>
          </div>
        </div>

        <div class="grupo-campo">
          <label for="assunto" class="rotulo-campo"><span>Com o que você precisa de ajuda? <span class="campo-obrigatorio" aria-hidden="true">*</span></span></label>
          <select id="assunto" name="assunto" class="input-ajuda" required>
            <option value="" disabled <?= $pedido['assunto'] === '' ? 'selected' : '' ?>>Escolha uma opção</option>
            <?php foreach (AJUDA_ASSUNTOS as $chave => $rotulo): ?>
              <option value="<?= e($chave) ?>" <?= $pedido['assunto'] === $chave ? 'selected' : '' ?>><?= e($rotulo) ?></option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="grupo-campo">
          <label for="mensagem" class="rotulo-campo"><span>Quer contar o que aconteceu? <small>(opcional)</small></span></label>
          <textarea id="mensagem" name="mensagem" class="input-ajuda" rows="4" maxlength="1000" placeholder="Ex.: o sistema não aceita o meu CEP"><?= e($pedido['mensagem']) ?></textarea>
        </div>

        <div class="grupo-campo">
          <label for="email" class="rotulo-campo"><span>E-mail do cadastro <small>(obrigatório se esqueceu a senha)</small></span></label>
          <input type="email" id="email" name="email" class="input-ajuda" value="<?= e($pedido['email']) ?>" maxlength="100" autocomplete="email">
        </div>

        <button type="submit" class="botao-principal" data-enviando="Enviando…"><span>Quero que me liguem</span></button>
      </form>
    </section>

    <a class="voltar" href="<?= e($voltarPara) ?>">&larr; <?= e($voltarRotulo) ?></a>

  </main>
  <script src="assets/js/autenticacao.js"></script>
</body>
</html>
