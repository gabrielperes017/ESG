<?php
declare(strict_types=1);

require __DIR__ . '/config/database.php';
require __DIR__ . '/includes/sessao.php';
require_once __DIR__ . '/includes/helpers.php';
require __DIR__ . '/includes/certificados.php';
require_once __DIR__ . '/includes/limite_tentativas.php';

$codigo         = strtoupper(trim((string) ($_GET['codigo'] ?? '')));
$dados          = false;
$erroDb         = false;
$mensagemEspera = '';
$buscou         = $codigo !== '';

if ($buscou) {
    try {
        $conexao = conectar();

        $segundosDeEspera = limite_segundos_restantes($conexao, 'validar_certificado', endereco_ip_do_visitante());

        if ($segundosDeEspera > 0) {
            $mensagemEspera = limite_mensagem($segundosDeEspera);
        } else {
            $coletaId = coleta_do_codigo($codigo);
            if ($coletaId !== null) {
                $dados = certificado_dados($conexao, $coletaId);
            }
            if ($dados === false) {
                limite_registrar_falha($conexao, 'validar_certificado', endereco_ip_do_visitante(), LIMITE_VALIDACAO_CERTIFICADO_IP);
            }
        }
    } catch (PDOException $excecao) {
        registrar_erro_banco('Validar certificado', $excecao);
        $erroDb = true;
    }
}
?>
<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Validar certificado - Bio Óleo</title>
    <link rel="icon" type="image/png" href="assets/img/favicon.png">
    <link rel="stylesheet" href="assets/css/certificado.css">
</head>
<body class="so-tela">
<main class="aviso">
    <img src="assets/img/logo-gota.png" alt="Bio Óleo" width="70">
    <h1>Validar certificado</h1>
    <p>Digite o código que aparece no certificado de destinação ambiental.</p>

    <form method="get" action="validar-certificado.php" class="form-validar">
        <label for="codigo" class="sr-only">Código</label>
        <input type="text" id="codigo" name="codigo" value="<?= e($codigo) ?>" placeholder="BIO-000000-XXXXXX" maxlength="20" autocomplete="off" required>
        <button type="submit">Validar</button>
    </form>

    <?php if ($erroDb): ?>
        <div class="resultado invalido" role="alert">Não foi possível consultar agora. Tente novamente em instantes.</div>
    <?php elseif ($buscou && $dados): ?>
        <div class="resultado valido" role="status">
            <strong>✔ Certificado válido</strong>
            <dl>
                <dt>Código</dt><dd><?= e(codigo_certificado((int) $dados['id'])) ?></dd>
                <dt>Cliente</dt><dd><?= e(nome_mascarado($dados['cliente'])) ?></dd>
                <dt>Quantidade</dt><dd><?= e(formatar_litros($dados['quantidade_litros'])) ?></dd>
                <dt>Coleta</dt><dd><?= e(formatar_data($dados['data_agendada'])) ?></dd>
                <dt>Entrega ao destino</dt><dd><?= e(!empty($dados['data_transporte']) ? formatar_data($dados['data_transporte']) : 'Entregue') ?></dd>
            </dl>
        </div>
    <?php elseif ($mensagemEspera !== ''): ?>
        <div class="resultado invalido" role="alert"><?= e($mensagemEspera) ?></div>
    <?php elseif ($buscou): ?>
        <div class="resultado invalido" role="alert"><strong>✖ Certificado não encontrado.</strong> Confira se o código foi digitado corretamente.</div>
    <?php endif; ?>
</main>
</body>
</html>
