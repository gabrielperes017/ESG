<?php
declare(strict_types=1);

require __DIR__ . '/config/database.php';
require __DIR__ . '/includes/sessao.php';
require __DIR__ . '/includes/layout.php';

exigir_login();
exigir_perfil(['Administrador'], 'vantagens');

$erro = '';
$mensagemAviso   = $_SESSION['flash'] ?? '';
unset($_SESSION['flash']);

$form = ['titulo' => '', 'descricao' => '', 'pontos' => ''];
$vantagens = [];
$resgates  = [];
$erroDados = false;

try {
    $pdo = conectar();

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $acao = (string) ($_POST['acao'] ?? '');

        if (!csrf_valido($_POST['csrf'] ?? null)) {
            $erro = 'A página expirou. Tente novamente.';

        } elseif ($acao === 'adicionar') {
            $form['titulo']    = trim((string) ($_POST['titulo'] ?? ''));
            $form['descricao'] = trim((string) ($_POST['descricao'] ?? ''));
            $form['pontos']    = trim((string) ($_POST['pontos'] ?? ''));

            if ($form['titulo'] === '' || mb_strlen($form['titulo']) > 100) {
                $erro = 'Informe o título da vantagem (até 100 caracteres).';
            } elseif (mb_strlen($form['descricao']) > 255) {
                $erro = 'A descrição pode ter no máximo 255 caracteres.';
            } elseif (!ctype_digit($form['pontos']) || (int) $form['pontos'] < 1 || (int) $form['pontos'] > 100000) {
                $erro = 'Informe quantos pontos a vantagem custa (número inteiro, de 1 a 100000).';
            } else {
                $pdo->prepare('INSERT INTO vantagens (titulo, descricao, pontos, ativa) VALUES (:t, :d, :p, 1)')
                    ->execute([':t' => $form['titulo'], ':d' => $form['descricao'] !== '' ? $form['descricao'] : null, ':p' => (int) $form['pontos']]);
                $_SESSION['flash'] = 'Vantagem adicionada.';
                header('Location: vantagens.php');
                exit;
            }

        } elseif ($acao === 'ativar') {
            $pdo->prepare('UPDATE vantagens SET ativa = :a WHERE id = :id')
                ->execute([':a' => ($_POST['ativa'] ?? '') === '1' ? 1 : 0, ':id' => (int) ($_POST['id'] ?? 0)]);
            $_SESSION['flash'] = 'Vantagem atualizada.';
            header('Location: vantagens.php');
            exit;

        } elseif ($acao === 'resgate') {
            $novo = (string) ($_POST['status'] ?? '');
            if (!in_array($novo, ['Entregue', 'Cancelado'], true)) {
                $erro = 'Situação inválida.';
            } else {
                $atualizacao = $pdo->prepare("UPDATE resgates SET status = :s WHERE id = :id AND status = 'Solicitado'");
                $atualizacao->execute([':s' => $novo, ':id' => (int) ($_POST['id'] ?? 0)]);
                if ($atualizacao->rowCount() === 1) {
                    $_SESSION['flash'] = $novo === 'Entregue' ? 'Resgate marcado como entregue.' : 'Resgate cancelado e pontos devolvidos ao cliente.';
                    header('Location: vantagens.php');
                    exit;
                }
                $erro = 'Esse resgate já foi tratado.';
            }
        }
    }

    $vantagens = $pdo->query('SELECT id, titulo, descricao, pontos, ativa FROM vantagens ORDER BY ativa DESC, pontos ASC, id ASC')->fetchAll();
    $resgates  = $pdo->query(
        "SELECT r.id, r.titulo, r.pontos, r.status, r.data_criacao, u.nome AS cliente, u.telefone, u.email
           FROM resgates r JOIN usuarios u ON u.id = r.cliente_id
          ORDER BY (r.status = 'Solicitado') DESC, r.id DESC LIMIT 100"
    )->fetchAll();
} catch (PDOException $excecao) {
    registrar_erro_banco('Vantagens', $excecao);
    $erroDados = true;
}

layout_abrir('Pontos e vantagens', 'vantagens');
?>
        <h1>Pontos e vantagens</h1>
        <p class="texto-suave">Cadastre as vantagens e entregue os resgates dos clientes. Regra: 1 ponto por litro inteiro entregue ao destino.</p>

        <?php if ($erroDados): ?>
            <div class="alerta alerta-erro" role="alert">Não foi possível carregar os dados. Rode o arquivo <code>banco/atualizacao_v09.sql</code> no MySQL Workbench e atualize a página.</div>
        <?php else: ?>

        <?php if ($erro !== ''): ?><div class="alerta alerta-erro" role="alert"><?= e($erro) ?></div><?php endif; ?>
        <?php if ($mensagemAviso !== ''): ?><div class="alerta alerta-info" role="status"><?= e($mensagemAviso) ?></div><?php endif; ?>

        <section class="cartao">
            <h2>Resgates dos clientes</h2>
            <div class="tabela-rolagem">
            <table>
                <thead><tr><th>Data</th><th>Cliente</th><th>Vantagem</th><th>Pontos</th><th>Situação</th><th>Ação</th></tr></thead>
                <tbody>
                <?php if (!$resgates): ?>
                    <tr><td class="vazio" colspan="6">Nenhum resgate ainda.</td></tr>
                <?php else: foreach ($resgates as $resgate): ?>
                    <tr>
                        <td><?= e(formatar_data(substr((string) $resgate['data_criacao'], 0, 10))) ?></td>
                        <td><?= e($resgate['cliente']) ?><br><small><?= e($resgate['telefone'] ?? $resgate['email']) ?></small></td>
                        <td><?= e($resgate['titulo']) ?></td>
                        <td><?= (int) $resgate['pontos'] ?></td>
                        <td><?= badge_resgate($resgate['status']) ?></td>
                        <td>
                            <?php if ($resgate['status'] === 'Solicitado'): ?>
                            <form method="post" action="vantagens.php" class="form-registro">
                                <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                                <input type="hidden" name="acao" value="resgate">
                                <input type="hidden" name="id" value="<?= (int) $resgate['id'] ?>">
                                <button type="submit" name="status" value="Entregue" class="botao-pequeno">Entregue</button>
                                <button type="submit" name="status" value="Cancelado" class="botao-pequeno botao-sair" data-confirmar="Cancelar e devolver os pontos ao cliente?">Cancelar</button>
                            </form>
                            <?php else: ?>-<?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
            </div>
        </section>

        <section class="cartao">
            <h2>Nova vantagem</h2>
            <form method="post" action="vantagens.php">
                <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="acao" value="adicionar">
                <div class="linha">
                    <div>
                        <label for="titulo">Título</label>
                        <input type="text" id="titulo" name="titulo" maxlength="100" value="<?= e($form['titulo']) ?>" required>
                    </div>
                    <div>
                        <label for="pontos">Custo em pontos</label>
                        <input type="text" inputmode="numeric" id="pontos" name="pontos" placeholder="Ex.: 100" value="<?= e($form['pontos']) ?>" required>
                    </div>
                </div>
                <label for="descricao">Descrição (opcional)</label>
                <input type="text" id="descricao" name="descricao" maxlength="255" value="<?= e($form['descricao']) ?>">
                <button type="submit">Adicionar vantagem</button>
            </form>
        </section>

        <section class="cartao">
            <h2>Vantagens cadastradas</h2>
            <div class="tabela-rolagem">
            <table>
                <thead><tr><th>Vantagem</th><th>Pontos</th><th>Situação</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($vantagens as $vantagem): $ativa = (int) $vantagem['ativa'] === 1; ?>
                    <tr>
                        <td><?= e($vantagem['titulo']) ?><br><small><?= e((string) $vantagem['descricao']) ?></small></td>
                        <td><?= (int) $vantagem['pontos'] ?></td>
                        <td><span class="badge <?= $ativa ? 'badge-concluida' : 'badge-cancelada' ?>"><?= $ativa ? 'Ativa' : 'Desativada' ?></span></td>
                        <td>
                            <form method="post" action="vantagens.php" class="form-inline">
                                <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                                <input type="hidden" name="acao" value="ativar">
                                <input type="hidden" name="id" value="<?= (int) $vantagem['id'] ?>">
                                <input type="hidden" name="ativa" value="<?= $ativa ? '0' : '1' ?>">
                                <button type="submit" class="botao-pequeno"><?= $ativa ? 'Desativar' : 'Ativar' ?></button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            </div>
            <p class="nota">Desativar tira a vantagem da vitrine dos clientes; resgates já feitos continuam valendo.</p>
        </section>

        <?php endif; ?>
<?php layout_fechar(); ?>
