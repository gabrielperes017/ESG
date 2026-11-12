<?php
declare(strict_types=1);

require __DIR__ . '/config/database.php';
require __DIR__ . '/includes/sessao.php';
require __DIR__ . '/includes/layout.php';
require __DIR__ . '/includes/pontos.php';

exigir_login();
exigir_perfil(['Cliente'], 'pontos');

$usuario = usuario_logado();
$erro = '';
$mensagemAviso   = $_SESSION['flash'] ?? '';
unset($_SESSION['flash']);

$extrato = ['ganhos' => [], 'total' => 0, 'gastos' => 0, 'saldo' => 0];
$vantagens = [];
$resgates  = [];
$erroDados = false;

try {
    $pdo = conectar();
    $mysql = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $acao = (string) ($_POST['acao'] ?? '');

        if (!csrf_valido($_POST['csrf'] ?? null)) {
            $erro = 'A página expirou. Tente novamente.';

        } elseif ($acao === 'resgatar') {
            $pdo->beginTransaction();
            $pdo->prepare('SELECT id FROM usuarios WHERE id = :id' . ($mysql ? ' FOR UPDATE' : ''))
                ->execute([':id' => $usuario['id']]);

            $consulta = $pdo->prepare('SELECT id, titulo, pontos FROM vantagens WHERE id = :id AND ativa = 1');
            $consulta->execute([':id' => (int) ($_POST['id'] ?? 0)]);
            $vantagem = $consulta->fetch();
            $saldo = pontos_extrato($pdo, $usuario['id'])['saldo'];

            if (!$vantagem) {
                $pdo->rollBack();
                $erro = 'Essa vantagem não está mais disponível.';
            } elseif ($saldo < (int) $vantagem['pontos']) {
                $pdo->rollBack();
                $erro = 'Pontos insuficientes para essa vantagem.';
            } else {
                $pdo->prepare(
                    "INSERT INTO resgates (cliente_id, vantagem_id, titulo, pontos, status) VALUES (:c, :v, :t, :p, 'Solicitado')"
                )->execute([':c' => $usuario['id'], ':v' => (int) $vantagem['id'], ':t' => $vantagem['titulo'], ':p' => (int) $vantagem['pontos']]);
                $pdo->commit();
                $_SESSION['flash'] = 'Resgate solicitado: ' . $vantagem['titulo'] . '. A equipe vai entrar em contato para entregar.';
                header('Location: pontos.php');
                exit;
            }

        } elseif ($acao === 'cancelar') {
            $atualizacao = $pdo->prepare("UPDATE resgates SET status = 'Cancelado' WHERE id = :id AND cliente_id = :c AND status = 'Solicitado'");
            $atualizacao->execute([':id' => (int) ($_POST['id'] ?? 0), ':c' => $usuario['id']]);
            if ($atualizacao->rowCount() === 1) {
                $_SESSION['flash'] = 'Resgate cancelado. Seus pontos voltaram para o saldo.';
                header('Location: pontos.php');
                exit;
            }
            $erro = 'Não foi possível cancelar esse resgate.';
        }
    }

    $extrato   = pontos_extrato($pdo, $usuario['id']);
    $vantagens = $pdo->query('SELECT id, titulo, descricao, pontos FROM vantagens WHERE ativa = 1 ORDER BY pontos ASC, id ASC')->fetchAll();

    $consulta = $pdo->prepare('SELECT id, titulo, pontos, status, data_criacao FROM resgates WHERE cliente_id = :c ORDER BY id DESC LIMIT 30');
    $consulta->execute([':c' => $usuario['id']]);
    $resgates = $consulta->fetchAll();
} catch (PDOException $excecao) {
    if (isset($pdo) && $pdo->inTransaction()) { $pdo->rollBack(); }
    registrar_erro_banco('Pontos', $excecao);
    $erroDados = true;
}

layout_abrir('Pontos e vantagens', 'pontos');
?>
        <h1>Pontos e vantagens</h1>
        <p class="texto-suave">Cada litro de óleo entregue ao destino vira ponto. Troque seus pontos por vantagens.</p>

        <?php if ($erroDados): ?>
            <div class="alerta alerta-erro" role="alert">Não foi possível carregar o programa agora. Se o problema continuar, fale com o administrador (o banco pode precisar da atualização <code>atualizacao_v09.sql</code>).</div>
        <?php else: ?>

        <?php if ($erro !== ''): ?><div class="alerta alerta-erro" role="alert"><?= e($erro) ?></div><?php endif; ?>
        <?php if ($mensagemAviso !== ''): ?><div class="alerta alerta-info" role="status"><?= e($mensagemAviso) ?></div><?php endif; ?>

        <section class="resumo" aria-label="Seus pontos">
            <div class="cartao"><h3>Saldo de pontos</h3><p class="numero"><?= $extrato['saldo'] ?></p><p class="nota">Disponível para resgate</p></div>
            <div class="cartao"><h3>Pontos ganhos</h3><p class="numero"><?= $extrato['total'] ?></p><p class="nota">1 ponto por litro inteiro entregue</p></div>
            <div class="cartao"><h3>Pontos usados</h3><p class="numero"><?= $extrato['gastos'] ?></p><p class="nota">Em resgates</p></div>
        </section>

        <section class="cartao">
            <h2>Vantagens disponíveis</h2>
            <?php if (!$vantagens): ?>
                <p class="vazio">Nenhuma vantagem disponível no momento.</p>
            <?php else: ?>
            <div class="grade-vantagens">
                <?php foreach ($vantagens as $vantagem): $pode = $extrato['saldo'] >= (int) $vantagem['pontos']; ?>
                <div class="vantagem">
                    <h3><?= e($vantagem['titulo']) ?></h3>
                    <p><?= e((string) $vantagem['descricao']) ?></p>
                    <p class="custo"><?= (int) $vantagem['pontos'] ?> pontos</p>
                    <form method="post" action="pontos.php" data-confirmar="<?= e('Resgatar “' . $vantagem['titulo'] . '” por ' . (int) $vantagem['pontos'] . ' pontos?') ?>">
                        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                        <input type="hidden" name="acao" value="resgatar">
                        <input type="hidden" name="id" value="<?= (int) $vantagem['id'] ?>">
                        <button type="submit" <?= $pode ? '' : 'disabled' ?>><?= $pode ? 'Resgatar' : 'Faltam ' . ((int) $vantagem['pontos'] - $extrato['saldo']) . ' pontos' ?></button>
                    </form>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </section>

        <section class="cartao">
            <h2>Meus resgates</h2>
            <div class="tabela-rolagem">
            <table>
                <thead><tr><th>Data</th><th>Vantagem</th><th>Pontos</th><th>Situação</th><th></th></tr></thead>
                <tbody>
                <?php if (!$resgates): ?>
                    <tr><td class="vazio" colspan="5">Você ainda não resgatou nenhuma vantagem.</td></tr>
                <?php else: foreach ($resgates as $resgate): ?>
                    <tr>
                        <td><?= e(formatar_data(substr((string) $resgate['data_criacao'], 0, 10))) ?></td>
                        <td><?= e($resgate['titulo']) ?></td>
                        <td><?= (int) $resgate['pontos'] ?></td>
                        <td><?= badge_resgate($resgate['status']) ?></td>
                        <td>
                            <?php if ($resgate['status'] === 'Solicitado'): ?>
                            <form method="post" action="pontos.php" class="form-inline" data-confirmar="Cancelar este resgate? Os pontos voltam para o seu saldo.">
                                <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                                <input type="hidden" name="acao" value="cancelar">
                                <input type="hidden" name="id" value="<?= (int) $resgate['id'] ?>">
                                <button type="submit" class="botao-pequeno botao-sair">Cancelar</button>
                            </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
            </div>
        </section>

        <section class="cartao">
            <h2>De onde vieram meus pontos</h2>
            <p class="nota">Só entram coletas concluídas com o óleo já entregue ao destino.</p>
            <div class="tabela-rolagem">
            <table>
                <thead><tr><th>Coleta</th><th>Quantidade</th><th>Pontos</th></tr></thead>
                <tbody>
                <?php if (!$extrato['ganhos']): ?>
                    <tr><td class="vazio" colspan="3">Ainda não há pontos. Eles aparecem quando o óleo coletado é entregue ao destino.</td></tr>
                <?php else: foreach ($extrato['ganhos'] as $ganho): ?>
                    <tr><td><?= e(formatar_data($ganho['data_agendada'])) ?></td><td><?= e(formatar_litros($ganho['quantidade_litros'])) ?></td><td>+<?= (int) $ganho['pontos'] ?></td></tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
            </div>
        </section>

        <?php endif; ?>
<?php layout_fechar(); ?>
