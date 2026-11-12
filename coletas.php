<?php
declare(strict_types=1);

require __DIR__ . '/config/database.php';
require __DIR__ . '/includes/sessao.php';
require __DIR__ . '/includes/layout.php';

exigir_login();
exigir_perfil(['Administrador', 'Operação'], 'coletas');

$usuario = usuario_logado();
$erro = '';
$mensagemAviso   = $_SESSION['flash'] ?? '';
unset($_SESSION['flash']);

$ORIGENS = ['Comercio' => 'Comércio', 'Residencia' => 'Residência', 'Industria' => 'Indústria'];

function origem_sugerida(?string $cargo): string
{
    $cargoMinusculo = mb_strtolower((string) $cargo);
    if (str_contains($cargoMinusculo, 'indústria') || str_contains($cargoMinusculo, 'empresa')) { return 'Industria'; }
    if (str_contains($cargoMinusculo, 'condom') || str_contains($cargoMinusculo, 'física') || str_contains($cargoMinusculo, 'residen')) { return 'Residencia'; }
    return 'Comercio';
}

$aRealizar = [];
$realizadas = [];
$erroDados = false;

try {
    $pdo = conectar();

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $acao = (string) ($_POST['acao'] ?? '');

        if (!csrf_valido($_POST['csrf'] ?? null)) {
            $erro = 'A página expirou. Tente novamente.';

        } elseif ($acao === 'registrar') {
            $coletaId     = (int) ($_POST['id'] ?? 0);
            $litrosTexto = str_replace(',', '.', trim((string) ($_POST['litros'] ?? '')));
            $origem = (string) ($_POST['origem'] ?? '');
            $entrega = (string) ($_POST['entrega'] ?? '');

            if (!is_numeric($litrosTexto) || (float) $litrosTexto <= 0 || (float) $litrosTexto > 9999) {
                $erro = 'Informe os litros realmente coletados (entre 0,5 e 9999).';
            } elseif (!isset($ORIGENS[$origem])) {
                $erro = 'Escolha a origem do óleo.';
            } elseif (!in_array($entrega, ['Entregue', 'Em trânsito'], true)) {
                $erro = 'Escolha a situação da entrega.';
            } else {
                $pdo->beginTransaction();
                $atualizacao = $pdo->prepare(
                    "UPDATE coletas SET status = 'Concluida', quantidade_litros = :l WHERE id = :id AND status = 'Agendada'"
                );
                $atualizacao->execute([':l' => round((float) $litrosTexto, 2), ':id' => $coletaId]);

                if ($atualizacao->rowCount() !== 1) {
                    $pdo->rollBack();
                    $erro = 'Esta coleta já foi registrada ou foi alterada por outra pessoa.';
                } else {
                    $pdo->prepare(
                        'INSERT INTO transportes (coleta_id, transportador_id, origem_tipo, data_transporte, status_entrega)
                         VALUES (:c, :t, :o, :d, :s)'
                    )->execute([':c' => $coletaId, ':t' => $usuario['id'], ':o' => $origem, ':d' => date('Y-m-d'), ':s' => $entrega]);
                    $pdo->commit();
                    $_SESSION['flash'] = 'Coleta registrada como concluída.';
                    header('Location: coletas.php');
                    exit;
                }
            }

        } elseif ($acao === 'regularizar') {
            $origem  = (string) ($_POST['origem'] ?? '');
            $entrega = (string) ($_POST['entrega'] ?? '');

            if (!isset($ORIGENS[$origem])) {
                $erro = 'Escolha a origem do óleo.';
            } elseif (!in_array($entrega, ['Entregue', 'Em trânsito'], true)) {
                $erro = 'Escolha a situação da entrega.';
            } else {
                $insercao = $pdo->prepare(
                    "INSERT INTO transportes (coleta_id, transportador_id, origem_tipo, data_transporte, status_entrega)
                     SELECT c.id, :t, :o, :d, :s FROM coletas c
                      WHERE c.id = :id AND c.status = 'Concluida'
                        AND NOT EXISTS (SELECT 1 FROM transportes x WHERE x.coleta_id = c.id)"
                );
                $insercao->execute([':t' => $usuario['id'], ':o' => $origem, ':d' => date('Y-m-d'), ':s' => $entrega, ':id' => (int) ($_POST['id'] ?? 0)]);
                if ($insercao->rowCount() === 1) {
                    $_SESSION['flash'] = 'Entrega registrada.' . ($entrega === 'Entregue' ? ' Os pontos e o certificado do cliente já estão disponíveis.' : '');
                    header('Location: coletas.php');
                    exit;
                }
                $erro = 'Esta coleta já tem registro de entrega ou não está concluída.';
            }

        } elseif ($acao === 'entregue') {
            $pdo->prepare("UPDATE transportes SET status_entrega = 'Entregue' WHERE id = :id AND status_entrega = 'Em trânsito'")
                ->execute([':id' => (int) ($_POST['id'] ?? 0)]);
            $_SESSION['flash'] = 'Entrega confirmada.';
            header('Location: coletas.php');
            exit;
        }
    }

    $aRealizar = $pdo->query(
        "SELECT c.id, c.data_agendada, c.quantidade_litros, u.nome AS cliente, u.cargo, u.telefone, " . endereco_colunas_sql('u') . "
           FROM coletas c JOIN usuarios u ON u.id = c.cliente_id
          WHERE c.status = 'Agendada' ORDER BY c.data_agendada ASC, c.id ASC LIMIT 100"
    )->fetchAll();

    $realizadas = $pdo->query(
        "SELECT c.id, c.data_agendada, c.quantidade_litros, u.nome AS cliente, u.cargo,
                t.id AS transporte_id, t.origem_tipo, t.status_entrega
           FROM coletas c
           JOIN usuarios u ON u.id = c.cliente_id
           LEFT JOIN transportes t ON t.coleta_id = c.id
          WHERE c.status = 'Concluida' ORDER BY c.data_agendada DESC, c.id DESC LIMIT 30"
    )->fetchAll();
} catch (PDOException $excecao) {
    if (isset($pdo) && $pdo->inTransaction()) { $pdo->rollBack(); }
    registrar_erro_banco('Coletas', $excecao);
    $erroDados = true;
}

$hoje = date('Y-m-d');

layout_abrir('Coletas', 'coletas');
?>
        <h1>Coletas</h1>
        <p class="texto-suave">Registre as coletas feitas e acompanhe a entrega do óleo.</p>

        <?php if ($erroDados): ?>
            <div class="alerta alerta-erro" role="alert">Não foi possível carregar as coletas agora. Atualize em instantes.</div>
        <?php else: ?>

        <?php if ($erro !== ''): ?><div class="alerta alerta-erro" role="alert"><?= e($erro) ?></div><?php endif; ?>
        <?php if ($mensagemAviso !== ''): ?><div class="alerta alerta-info" role="status"><?= e($mensagemAviso) ?></div><?php endif; ?>

        <section class="cartao">
            <h2>A realizar</h2>
            <p class="nota">Coletas agendadas. Depois de coletar, informe os litros reais e a situação da entrega.</p>

            <?php if (!$aRealizar): ?>
                <p class="vazio">Nenhuma coleta agendada no momento.</p>
            <?php else: ?>
            <div class="tabela-rolagem">
            <table>
                <thead><tr><th>Data</th><th>Cliente</th><th>Previsto</th><th>Registrar coleta</th></tr></thead>
                <tbody>
                <?php foreach ($aRealizar as $coleta): $atrasada = $coleta['data_agendada'] < $hoje; ?>
                    <tr>
                        <td><?= e(formatar_data($coleta['data_agendada'])) ?><?= $atrasada ? ' <span class="badge badge-pendente">Atrasada</span>' : '' ?></td>
                        <td><?= e($coleta['cliente']) ?><br><small><?= e($coleta['telefone'] ?? '') ?></small><br><?= endereco_bloco_html($coleta) ?></td>
                        <td><?= e(formatar_litros($coleta['quantidade_litros'])) ?></td>
                        <td>
                            <form method="post" action="coletas.php" class="form-registro">
                                <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                                <input type="hidden" name="acao" value="registrar">
                                <input type="hidden" name="id" value="<?= (int) $coleta['id'] ?>">
                                <input type="text" inputmode="decimal" name="litros" value="<?= e(rtrim(rtrim(number_format((float) $coleta['quantidade_litros'], 2, ',', ''), '0'), ',')) ?>" aria-label="Litros coletados" size="5" required>
                                <select name="origem" aria-label="Origem">
                                    <?php foreach ($ORIGENS as $chaveOrigem => $rotuloOrigem): ?>
                                        <option value="<?= e($chaveOrigem) ?>" <?= origem_sugerida($coleta['cargo']) === $chaveOrigem ? 'selected' : '' ?>><?= e($rotuloOrigem) ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <select name="entrega" aria-label="Entrega">
                                    <option value="Entregue">Entregue</option>
                                    <option value="Em trânsito">Em trânsito</option>
                                </select>
                                <button type="submit" class="botao-pequeno">Concluir</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            </div>
            <?php endif; ?>
        </section>

        <section class="cartao">
            <h2>Realizadas</h2>
            <p class="nota">Últimas 30 coletas concluídas.</p>
            <div class="tabela-rolagem">
            <table>
                <thead><tr><th>Data</th><th>Cliente</th><th>Litros</th><th>Origem</th><th>Entrega</th></tr></thead>
                <tbody>
                <?php if (!$realizadas): ?>
                    <tr><td class="vazio" colspan="5">Nenhuma coleta concluída ainda.</td></tr>
                <?php else: foreach ($realizadas as $coleta): ?>
                    <tr>
                        <td><?= e(formatar_data($coleta['data_agendada'])) ?></td>
                        <td><?= e($coleta['cliente']) ?></td>
                        <td><?= e(formatar_litros($coleta['quantidade_litros'])) ?></td>
                        <td><?= e($ORIGENS[$coleta['origem_tipo']] ?? '-') ?></td>
                        <td>
                            <?php if ($coleta['status_entrega'] === null): ?>
                                <span class="badge badge-cancelada">Sem registro</span>
                                <form method="post" action="coletas.php" class="form-registro">
                                    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                                    <input type="hidden" name="acao" value="regularizar">
                                    <input type="hidden" name="id" value="<?= (int) $coleta['id'] ?>">
                                    <select name="origem" aria-label="Origem">
                                        <?php foreach ($ORIGENS as $chaveOrigem => $rotuloOrigem): ?>
                                            <option value="<?= e($chaveOrigem) ?>" <?= origem_sugerida($coleta['cargo']) === $chaveOrigem ? 'selected' : '' ?>><?= e($rotuloOrigem) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <select name="entrega" aria-label="Entrega">
                                        <option value="Entregue">Entregue</option>
                                        <option value="Em trânsito">Em trânsito</option>
                                    </select>
                                    <button type="submit" class="botao-pequeno">Registrar entrega</button>
                                </form>
                            <?php elseif ($coleta['status_entrega'] === 'Entregue'): ?>
                                <span class="badge badge-concluida">Entregue</span>
                            <?php else: ?>
                                <span class="badge badge-pendente"><?= e($coleta['status_entrega']) ?></span>
                                <?php if ($coleta['status_entrega'] === 'Em trânsito'): ?>
                                <form method="post" action="coletas.php" class="form-inline">
                                    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                                    <input type="hidden" name="acao" value="entregue">
                                    <input type="hidden" name="id" value="<?= (int) $coleta['transporte_id'] ?>">
                                    <button type="submit" class="botao-pequeno">Marcar entregue</button>
                                </form>
                                <?php endif; ?>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
            </div>
        </section>

        <?php endif; ?>
<?php layout_fechar(); ?>
