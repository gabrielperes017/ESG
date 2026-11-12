<?php
declare(strict_types=1);

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/menu.php';

function layout_abrir(string $titulo, string $ativo): void
{
    $usuario = usuario_logado();
    $itens   = itens_menu($usuario['perfil']);
    $inicial = mb_strtoupper(mb_substr($usuario['nome'], 0, 1));
    ?>
<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($titulo) ?> - Bio Óleo</title>
    <link rel="icon" type="image/png" href="assets/img/favicon.png">
    <link rel="stylesheet" href="assets/css/estilo.css">
    <script src="assets/js/interface.js" defer></script>
    <script src="assets/js/mostrar-senha.js" defer></script>
    <script src="assets/js/endereco.js" defer></script>
</head>
<body>
<a class="pular-link" href="#conteudo">Pular para o conteúdo</a>

<aside class="menu-lateral">
    <div class="logo">
        <img class="gota-img" src="assets/img/logo-gota.png" alt="Bio Óleo" width="70">
        <h2>Bio Óleo</h2>
        <p>Reciclando hoje,<br>preservando o amanhã</p>
    </div>

    <nav aria-label="Menu principal">
        <?php foreach ($itens as $item): ?>
            <?php if ($item['chave'] === $ativo): ?>
                <a class="ativo" href="<?= e($item['arquivo']) ?>" aria-current="page"><span class="icone"><?= icone($item['icone']) ?></span><?= e($item['rotulo']) ?></a>
            <?php elseif (pagina_pronta($item['arquivo'])): ?>
                <a href="<?= e($item['arquivo']) ?>"><span class="icone"><?= icone($item['icone']) ?></span><?= e($item['rotulo']) ?></a>
            <?php else: ?>
                <span class="desativado" aria-disabled="true" title="Em desenvolvimento"><span class="icone"><?= icone($item['icone']) ?></span><?= e($item['rotulo']) ?></span>
            <?php endif; ?>
        <?php endforeach; ?>
    </nav>

    <form class="menu-sair" method="post" action="logout.php">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <button type="submit"><span class="icone"><?= icone('sair') ?></span>Sair</button>
    </form>
</aside>

<div class="area">
    <header class="topo">
        <div class="topo-usuario">
            <span class="avatar" aria-hidden="true"><?= e($inicial) ?></span>
            <span class="topo-nome"><?= e($usuario['nome']) ?><small><?= e($usuario['perfil']) ?></small></span>
        </div>
    </header>

    <main class="conteudo" id="conteudo">
<?php
    if (banco_desatualizado() && depuracao_ativa()) {
        echo '<div class="alerta alerta-erro" role="alert"><strong>Aviso para quem cuida do sistema:</strong> ' . e(BANCO_DESATUALIZADO_AVISO) . '</div>';
    }
}

function layout_fechar(): void
{
    ?>
    </main>

    <footer class="rodape-texto">Bio Óleo: coleta e destinação correta de óleo de cozinha usado.</footer>
</div>
</body>
</html>
<?php
}

function exigir_perfil(array $perfisPermitidos, string $ativo = ''): void
{
    $usuario = usuario_logado();
    if (in_array($usuario['perfil'], $perfisPermitidos, true)) {
        return;
    }
    http_response_code(403);
    layout_abrir('Acesso restrito', $ativo);
    ?>
        <h1>Acesso restrito</h1>
        <div class="alerta alerta-erro" role="alert">Seu perfil não tem permissão para ver esta página.</div>
        <p><a class="link" href="dashboard.php">Voltar ao início</a></p>
    <?php
    layout_fechar();
    exit;
}
