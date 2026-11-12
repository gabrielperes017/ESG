<?php
declare(strict_types=1);

require __DIR__ . '/config/database.php';
require __DIR__ . '/includes/sessao.php';
require __DIR__ . '/includes/layout.php';

exigir_login();
exigir_perfil(['Cliente'], 'solicitar');

const MAX_ABERTAS = 3;

$usuario = usuario_logado();
$erro    = '';
$form    = ['data' => '', 'litros' => ''];
$abertas = 0;
$erroDados = false;

try {
    $pdo = conectar();

    $consulta = $pdo->prepare("SELECT COUNT(*) FROM coletas WHERE cliente_id = :c AND status = 'Pendente'");
    $consulta->execute([':c' => $usuario['id']]);
    $abertas = (int) $consulta->fetchColumn();

    $consulta = $pdo->prepare('SELECT ' . endereco_colunas_sql('u') . ' FROM usuarios u WHERE u.id = :id');
    $consulta->execute([':id' => $usuario['id']]);
    $enderecoDaColeta = $consulta->fetch() ?: null;

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $form['data']   = trim((string) ($_POST['data'] ?? ''));
        $form['litros'] = trim((string) ($_POST['litros'] ?? ''));
        $litrosTexto = str_replace(',', '.', $form['litros']);
        $data      = DateTime::createFromFormat('!Y-m-d', $form['data']);

        if (!csrf_valido($_POST['csrf'] ?? null)) {
            $erro = 'A página expirou. Tente novamente.';
        } elseif ($abertas >= MAX_ABERTAS) {
            $erro = 'Você já tem ' . MAX_ABERTAS . ' solicitações aguardando retorno. Aguarde o contato da equipe ou cancele uma delas.';
        } elseif (!$data || $data->format('Y-m-d') !== $form['data']) {
            $erro = 'Informe uma data válida.';
        } elseif ($data < new DateTime('today')) {
            $erro = 'A data não pode ser no passado.';
        } elseif (!is_numeric($litrosTexto) || (float) $litrosTexto < 0.5 || (float) $litrosTexto > 9999) {
            $erro = 'Informe a quantidade de litros (entre 0,5 e 9999).';
        } else {
            $pdo->prepare(
                "INSERT INTO coletas (cliente_id, quantidade_litros, data_agendada, status)
                 VALUES (:c, :l, :d, 'Pendente')"
            )->execute([':c' => $usuario['id'], ':l' => round((float) $litrosTexto, 2), ':d' => $form['data']]);

            $_SESSION['flash'] = 'Solicitação enviada! Nossa equipe vai confirmar a data da coleta.';
            header('Location: acompanhar-coleta.php');
            exit;
        }
    }
} catch (PDOException $excecao) {
    registrar_erro_banco('Solicitar coleta', $excecao);
    $erroDados = true;
}

layout_abrir('Solicitar coleta', 'solicitar');
?>
        <h1>Solicitar coleta</h1>
        <p class="texto-suave">Informe quanto óleo você tem e uma data de preferência. A equipe confirma o agendamento.</p>

        <?php if ($erroDados): ?>
            <div class="alerta alerta-erro" role="alert">Não foi possível carregar a página agora. Atualize em instantes.</div>
        <?php else: ?>

        <?php if ($erro !== ''): ?><div class="alerta alerta-erro" role="alert"><?= e($erro) ?></div><?php endif; ?>

        <section class="cartao">
            <h2>Nova solicitação</h2>
            <div class="alerta alerta-info" role="note">
                <strong>Local da coleta:</strong> <?= e(endereco_texto($enderecoDaColeta)) ?>
                <?php if (trim((string) ($enderecoDaColeta['ponto_referencia'] ?? '')) !== ''): ?>
                    <br>Referência: <?= e((string) $enderecoDaColeta['ponto_referencia']) ?>
                <?php endif; ?>
                <br><a class="link" href="perfil.php#endereco">Endereço errado? Corrigir antes de solicitar</a>
            </div>
            <form method="post" action="solicitar-coleta.php">
                <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">

                <div class="linha">
                    <div>
                        <label for="litros">Quantidade estimada de óleo (litros)</label>
                        <input type="text" inputmode="decimal" id="litros" name="litros" placeholder="Ex.: 40" value="<?= e($form['litros']) ?>" required>
                    </div>
                    <div>
                        <label for="data">Data de preferência</label>
                        <input type="date" id="data" name="data" min="<?= e(date('Y-m-d')) ?>" value="<?= e($form['data']) ?>" required>
                    </div>
                </div>

                <p class="nota">Dica: guarde o óleo usado em garrafas PET bem fechadas, sem água nem restos de comida.</p>
                <button type="submit" <?= $abertas >= MAX_ABERTAS ? 'disabled' : '' ?>>Enviar solicitação</button>
            </form>
            <p class="nota">Está com dificuldade para pedir a coleta? <a class="link" href="ajuda.php?assunto=Agendamento">Peça ajuda: nossa equipe liga para você</a>.</p>
            <?php if ($abertas > 0): ?>
                <p class="nota">Você tem <?= $abertas ?> solicitação(ões) aguardando retorno. <a class="link" href="acompanhar-coleta.php">Acompanhar</a></p>
            <?php endif; ?>
        </section>

        <?php endif; ?>
<?php layout_fechar(); ?>
