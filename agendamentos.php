<?php
declare(strict_types=1);

require __DIR__ . '/config/database.php';
require __DIR__ . '/includes/sessao.php';
require __DIR__ . '/includes/layout.php';

exigir_login();
exigir_perfil(['Administrador', 'Operação', 'Visualizar'], 'agendamentos');

$usuario   = usuario_logado();
$podeAgendar   = perfil_pode($usuario['perfil'], 'agendar');
$podeGerenciar = $usuario['perfil'] === 'Administrador';

const STATUS_VALIDOS = ['Agendada', 'Pendente', 'Concluida', 'Cancelada'];

$erro = '';
$mensagemAviso   = $_SESSION['flash'] ?? '';
unset($_SESSION['flash']);

$form = ['cliente_id' => '', 'data' => '', 'litros' => ''];
$filtro = (string) ($_GET['status'] ?? '');
if (!in_array($filtro, STATUS_VALIDOS, true)) {
    $filtro = '';
}

$clientes = [];
$lista    = [];
$erroDados = false;

try {
    $pdo = conectar();

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $acao = (string) ($_POST['acao'] ?? '');

        if (!csrf_valido($_POST['csrf'] ?? null)) {
            $erro = 'A página expirou. Tente novamente.';

        } elseif ($acao === 'agendar') {
            if (!$podeAgendar) {
                http_response_code(403);
                $erro = 'Seu perfil não pode criar agendamentos.';
            } else {
                $form['cliente_id'] = (string) ($_POST['cliente_id'] ?? '');
                $form['data']       = trim((string) ($_POST['data'] ?? ''));
                $form['litros']     = trim((string) ($_POST['litros'] ?? ''));

                $litros = (float) str_replace(',', '.', $form['litros']);
                $data   = DateTime::createFromFormat('!Y-m-d', $form['data']);

                $consultaCliente = $pdo->prepare(
                    "SELECT COUNT(*) FROM usuarios u JOIN perfis_acesso p ON p.id = u.perfil_id
                      WHERE u.id = :id AND p.nome = 'Cliente' AND u.status_conta = 'Ativa'"
                );
                $consultaCliente->execute([':id' => (int) $form['cliente_id']]);

                if ((int) $consultaCliente->fetchColumn() === 0) {
                    $erro = 'Escolha um cliente ativo.';
                } elseif (!$data || $data->format('Y-m-d') !== $form['data']) {
                    $erro = 'Informe uma data válida.';
                } elseif ($data < new DateTime('today')) {
                    $erro = 'A data do agendamento não pode ser no passado.';
                } elseif (!is_numeric(str_replace(',', '.', $form['litros'])) || $litros <= 0 || $litros > 9999) {
                    $erro = 'Informe a quantidade de litros (entre 0,5 e 9999).';
                } else {
                    $pdo->prepare(
                        "INSERT INTO coletas (cliente_id, quantidade_litros, data_agendada, status)
                         VALUES (:c, :l, :d, 'Agendada')"
                    )->execute([':c' => (int) $form['cliente_id'], ':l' => round($litros, 2), ':d' => $form['data']]);
                    $_SESSION['flash'] = 'Coleta agendada para ' . $data->format('d/m/Y') . '.';
                    header('Location: agendamentos.php');
                    exit;
                }
            }

        } elseif ($acao === 'confirmar') {
            $dataTexto = trim((string) ($_POST['data'] ?? ''));
            $data    = DateTime::createFromFormat('!Y-m-d', $dataTexto);
            if (!$podeAgendar) {
                http_response_code(403);
                $erro = 'Seu perfil não pode confirmar agendamentos.';
            } elseif (!$data || $data->format('Y-m-d') !== $dataTexto || $data < new DateTime('today')) {
                $erro = 'Informe uma data válida (de hoje em diante) para confirmar.';
            } else {
                $atualizacao = $pdo->prepare("UPDATE coletas SET status = 'Agendada', data_agendada = :d WHERE id = :id AND status = 'Pendente'");
                $atualizacao->execute([':d' => $dataTexto, ':id' => (int) ($_POST['id'] ?? 0)]);
                if ($atualizacao->rowCount() === 1) {
                    $_SESSION['flash'] = 'Coleta confirmada para ' . $data->format('d/m/Y') . '.';
                    header('Location: agendamentos.php' . ($filtro !== '' ? '?status=' . $filtro : ''));
                    exit;
                }
                $erro = 'Esta solicitação já foi confirmada ou alterada.';
            }

        } elseif ($acao === 'status') {
            $novo = (string) ($_POST['status'] ?? '');
            if (!$podeGerenciar) {
                http_response_code(403);
                $erro = 'Só o administrador pode alterar o status de um agendamento.';
            } elseif (!in_array($novo, STATUS_VALIDOS, true)) {
                $erro = 'Status inválido.';
            } elseif ($novo === 'Concluida') {
                $erro = 'Para concluir uma coleta use a tela Coletas: lá você informa os litros e a entrega, e é isso que gera os pontos e o certificado do cliente.';
            } else {
                $pdo->prepare('UPDATE coletas SET status = :s WHERE id = :id')
                    ->execute([':s' => $novo, ':id' => (int) ($_POST['id'] ?? 0)]);
                $_SESSION['flash'] = 'Status atualizado.';
                header('Location: agendamentos.php' . ($filtro !== '' ? '?status=' . $filtro : ''));
                exit;
            }
        }
    }

    if ($podeAgendar) {
        $clientes = $pdo->query(
            "SELECT u.id, u.nome FROM usuarios u JOIN perfis_acesso p ON p.id = u.perfil_id
              WHERE p.nome = 'Cliente' AND u.status_conta = 'Ativa' ORDER BY u.nome"
        )->fetchAll();
    }

    $sql = "SELECT c.id, c.data_agendada, c.quantidade_litros, c.status, u.nome AS cliente, " . endereco_colunas_sql('u') . "
              FROM coletas c JOIN usuarios u ON u.id = c.cliente_id"
         . ($filtro !== '' ? ' WHERE c.status = :s' : '')
         . ' ORDER BY c.data_agendada DESC, c.id DESC LIMIT 100';
    $consulta = $pdo->prepare($sql);
    $consulta->execute($filtro !== '' ? [':s' => $filtro] : []);
    $lista = $consulta->fetchAll();
} catch (PDOException $excecao) {
    registrar_erro_banco('Agendamentos', $excecao);
    $erroDados = true;
}

$rotulos = ['' => 'Todos', 'Agendada' => 'Agendadas', 'Pendente' => 'Pendentes', 'Concluida' => 'Concluídas', 'Cancelada' => 'Canceladas'];

layout_abrir('Agendamentos', 'agendamentos');
?>
        <h1>Agendamentos</h1>
        <p class="texto-suave">
            <?= $podeAgendar ? 'Agende as coletas de óleo e acompanhe as datas.' : 'Consulte as coletas agendadas.' ?>
            <?php if (!$podeAgendar): ?>Seu perfil é somente para visualizar.<?php endif; ?>
        </p>

        <?php if ($erroDados): ?>
            <div class="alerta alerta-erro" role="alert">Não foi possível carregar os dados agora. Atualize a página em instantes.</div>
        <?php else: ?>

        <?php if ($erro !== ''): ?><div class="alerta alerta-erro" role="alert"><?= e($erro) ?></div><?php endif; ?>
        <?php if ($mensagemAviso !== ''): ?><div class="alerta alerta-info" role="status"><?= e($mensagemAviso) ?></div><?php endif; ?>

        <?php if ($podeAgendar): ?>
        <section class="cartao">
            <h2>Agendar coleta</h2>
            <form method="post" action="agendamentos.php">
                <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="acao" value="agendar">

                <div class="linha">
                    <div>
                        <label for="cliente_id">Cliente</label>
                        <select id="cliente_id" name="cliente_id" required>
                            <option value="">Selecione…</option>
                            <?php foreach ($clientes as $cliente): ?>
                                <option value="<?= (int) $cliente['id'] ?>" <?= (string) $cliente['id'] === $form['cliente_id'] ? 'selected' : '' ?>><?= e($cliente['nome']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label for="data">Data da coleta</label>
                        <input type="date" id="data" name="data" min="<?= e(date('Y-m-d')) ?>" value="<?= e($form['data']) ?>" required>
                    </div>
                    <div>
                        <label for="litros">Quantidade estimada (L)</label>
                        <input type="text" inputmode="decimal" id="litros" name="litros" placeholder="Ex.: 40" value="<?= e($form['litros']) ?>" required>
                    </div>
                </div>

                <button type="submit">Agendar</button>
            </form>
        </section>
        <?php endif; ?>

        <section class="cartao">
            <div class="cartao-cabeca">
                <h2>Coletas agendadas</h2>
            </div>

            <nav class="filtros" aria-label="Filtrar por status">
                <?php foreach ($rotulos as $chave => $rotulo): ?>
                    <a href="agendamentos.php<?= $chave !== '' ? '?status=' . e($chave) : '' ?>"
                       class="filtro <?= $filtro === $chave ? 'ativo' : '' ?>"><?= e($rotulo) ?></a>
                <?php endforeach; ?>
            </nav>

            <div class="tabela-rolagem">
            <table>
                <thead>
                    <tr><th>Cliente</th><th>Data</th><th>Quantidade</th><th>Status</th><?php if ($podeAgendar): ?><th>Confirmar solicitação</th><?php endif; ?><?php if ($podeGerenciar): ?><th>Alterar status</th><?php endif; ?></tr>
                </thead>
                <tbody>
                <?php if (!$lista): ?>
                    <tr><td class="vazio" colspan="<?= 4 + ($podeAgendar ? 1 : 0) + ($podeGerenciar ? 1 : 0) ?>">Nenhum agendamento encontrado.</td></tr>
                <?php else: foreach ($lista as $agendamento): ?>
                    <tr>
                        <td><?= e($agendamento['cliente']) ?><br><?= endereco_bloco_html($agendamento) ?></td>
                        <td><?= e(formatar_data($agendamento['data_agendada'])) ?></td>
                        <td><?= e(formatar_litros($agendamento['quantidade_litros'])) ?></td>
                        <td><?= badge_status($agendamento['status']) ?></td>
                        <?php if ($podeAgendar): ?>
                        <td>
                            <?php if ($agendamento['status'] === 'Pendente'): ?>
                            <form method="post" action="agendamentos.php<?= $filtro !== '' ? '?status=' . e($filtro) : '' ?>" class="form-registro">
                                <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                                <input type="hidden" name="acao" value="confirmar">
                                <input type="hidden" name="id" value="<?= (int) $agendamento['id'] ?>">
                                <input type="date" name="data" min="<?= e(date('Y-m-d')) ?>" value="<?= e(max($agendamento['data_agendada'], date('Y-m-d'))) ?>" aria-label="Data da coleta" required>
                                <button type="submit" class="botao-pequeno">Confirmar</button>
                            </form>
                            <?php else: ?>-<?php endif; ?>
                        </td>
                        <?php endif; ?>
                        <?php if ($podeGerenciar): ?>
                        <td>
                            <select name="status" form="s-<?= (int) $agendamento['id'] ?>" aria-label="Novo status">
                                <?php foreach (['Agendada' => 'Agendada', 'Pendente' => 'Pendente', 'Concluida' => 'Concluída', 'Cancelada' => 'Cancelada'] as $chave => $rotulo): ?>
                                    <?php if ($chave === 'Concluida' && $agendamento['status'] !== 'Concluida') { continue; } ?>
                                    <option value="<?= e($chave) ?>" <?= $agendamento['status'] === $chave ? 'selected' : '' ?>><?= e($rotulo) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <form id="s-<?= (int) $agendamento['id'] ?>" method="post" action="agendamentos.php<?= $filtro !== '' ? '?status=' . e($filtro) : '' ?>" class="form-inline">
                                <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                                <input type="hidden" name="acao" value="status">
                                <input type="hidden" name="id" value="<?= (int) $agendamento['id'] ?>">
                                <button type="submit" class="botao-pequeno">Salvar</button>
                            </form>
                        </td>
                        <?php endif; ?>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
            </div>
            <p class="nota">Mostrando até 100 agendamentos, do mais recente para o mais antigo.</p>
        </section>

        <?php endif; ?>
<?php layout_fechar(); ?>
