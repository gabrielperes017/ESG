<?php
declare(strict_types=1);

require __DIR__ . '/config/database.php';
require __DIR__ . '/includes/sessao.php';
require_once __DIR__ . '/includes/helpers.php';
require __DIR__ . '/includes/certificados.php';
require_once __DIR__ . '/includes/google.php';

exigir_login();
$usuario = usuario_logado();

$dados = false;
try {
    $dados = certificado_dados(conectar(), (int) ($_GET['id'] ?? 0));
} catch (PDOException $excecao) {
    registrar_erro_banco('Certificado', $excecao);
}

if (!$dados || ($usuario['perfil'] !== 'Administrador' && (int) $dados['cliente_id'] !== $usuario['id'])) {
    http_response_code(404);
    ?><!doctype html><html lang="pt-BR"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Certificado não encontrado</title><link rel="stylesheet" href="assets/css/certificado.css"></head>
    <body class="so-tela"><main class="aviso"><h1>Certificado não encontrado</h1>
    <p>Ele só fica disponível depois que o óleo da coleta é entregue ao destino final.</p>
    <p><a href="certificados.php">Voltar para Certificados</a></p></main></body></html><?php
    exit;
}

$codigo = codigo_certificado((int) $dados['id']);
$urlValidar = url_base() . '/validar-certificado.php?codigo=' . $codigo;

$tamanhoNome = mb_strlen((string) $dados['cliente']);
$classeNome = $tamanhoNome > 40 ? ' nome-longo' : ($tamanhoNome > 28 ? ' nome-medio' : '');
?>
<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Certificado <?= e($codigo) ?> - Bio Óleo</title>
    <link rel="icon" type="image/png" href="assets/img/favicon.png">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,600;0,700;1,600&amp;family=Montserrat:wght@400;500;600&amp;display=swap">
    <link rel="stylesheet" href="assets/css/certificado.css">
    <script src="assets/js/interface.js" defer></script>
</head>
<body>
<div class="barra-acoes">
    <a href="certificados.php">&larr; Voltar</a>
    <button type="button" data-imprimir>Imprimir / Salvar em PDF</button>
</div>

<main class="folha-certificado">
  <div class="escala">
    <div class="moldura">
      <div class="papel">
        <span class="vertice v1" aria-hidden="true"></span>
        <span class="vertice v2" aria-hidden="true"></span>
        <span class="vertice v3" aria-hidden="true"></span>
        <span class="vertice v4" aria-hidden="true"></span>

        <div class="filete">
            <header class="cabeca">
                <img class="logo" src="assets/img/logo-certificado.png" alt="Bio Óleo" width="88" height="124">
                <p class="marca">Bio Óleo</p>
            </header>

            <h1><span class="grande">Certificado</span><span class="sub">de Destinação Ambiental</span></h1>

            <div class="corpo">
                <p class="texto">Certificamos que</p>
                <p class="nome<?= $classeNome ?>"><?= e($dados['cliente']) ?></p>
                <p class="texto detalhe">
                    destinou <strong><?= e(formatar_litros($dados['quantidade_litros'])) ?></strong> de óleo de cozinha usado
                    à reciclagem, coletado em <strong><?= e(formatar_data($dados['data_agendada'])) ?></strong> e entregue ao destino final<?= !empty($dados['data_transporte']) ? ' em <strong>' . e(formatar_data($dados['data_transporte'])) . '</strong>' : '' ?>, contribuindo para a destinação
                    ambientalmente correta desse resíduo.
                </p>
            </div>

            <div class="rodape-cert">
                <div>
                    <span class="rotulo">Código do certificado</span>
                    <span class="codigo"><?= e($codigo) ?></span>
                </div>
                <div>
                    <span class="rotulo">Emitido em</span>
                    <span class="data"><?= e(date('d/m/Y')) ?></span>
                </div>
                <div class="validar">
                    <span class="rotulo">Confira a autenticidade em</span>
                    <span class="url"><?= e($urlValidar) ?></span>
                </div>
            </div>
        </div>
      </div>
    </div>
  </div>
</main>
</body>
</html>
