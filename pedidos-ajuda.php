<?php
declare(strict_types=1);

require __DIR__ . '/config/database.php';
require __DIR__ . '/includes/sessao.php';
require __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/ajuda.php';

exigir_login();
exigir_perfil(['Administrador', 'Operação'], 'pedidos-ajuda');

$ehAdministrador = usuario_logado()['perfil'] === 'Administrador';

$erro = '';
$mensagemAviso = $_SESSION['flash'] ?? '';
unset($_SESSION['flash']);

$abertos = [];
$contasDaSenha = [];
$resolvidos = [];
$erroDados = false;

try {
    $pdo = conectar();

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!csrf_valido($_POST['csrf'] ?? null)) {
            $erro = 'A página expirou. Tente novamente.';
        } elseif (($_POST['acao'] ?? '') === 'resolver') {
            $atualizacao = $pdo->prepare(
                "UPDATE pedidos_ajuda SET status = 'Resolvido', data_resolucao = :agora WHERE id = :id AND status = 'Aberto'"
            );
            $atualizacao->execute([':agora' => date('Y-m-d H:i:s'), ':id' => (int) ($_POST['id'] ?? 0)]);
            $_SESSION['flash'] = $atualizacao->rowCount() === 1
                ? 'Pedido marcado como resolvido.'
                : 'Nada foi alterado: o pedido não existe ou já estava resolvido.';
            header('Location: pedidos-ajuda.php');
            exit;
        }
    }

    $abertos = $pdo->query(
        "SELECT a.id, a.nome, a.telefone, a.email, a.assunto, a.mensagem, a.data_criacao, a.usuario_id
           FROM pedidos_ajuda a WHERE a.status = 'Aberto' ORDER BY a.data_criacao ASC, a.id ASC LIMIT 200"
    )->fetchAll();

    if ($ehAdministrador) {
        $buscaConta = $pdo->prepare('SELECT id, nome, telefone FROM usuarios WHERE email = :email LIMIT 1');
        foreach ($abertos as $pedido) {
            if ($pedido['assunto'] === 'Senha' && ($pedido['email'] ?? '') !== '') {
                $buscaConta->execute([':email' => $pedido['email']]);
                $contasDaSenha[(int) $pedido['id']] = $buscaConta->fetch() ?: false;
            }
        }
    }

    $resolvidos = $pdo->query(
        "SELECT a.id, a.nome, a.telefone, a.assunto, a.data_criacao, a.data_resolucao
           FROM pedidos_ajuda a WHERE a.status = 'Resolvido' ORDER BY a.data_resolucao DESC, a.id DESC LIMIT 20"
    )->fetchAll();
} catch (PDOException $excecao) {
    registrar_erro_banco('Pedidos de ajuda', $excecao);
    $erroDados = true;
}

layout_abrir('Pedidos de ajuda', 'pedidos-ajuda');
?>
        <h1>Pedidos de ajuda</h1>
        <p class="texto-suave">Clientes que não conseguiram se cadastrar, entrar, pedir uma coleta ou que esqueceram a senha. Ligue para eles e marque como resolvido.</p>

        <?php if ($ehAdministrador): ?>
            <p class="nota"><strong>Esqueci minha senha:</strong> antes de trocar, confirme que é a própria pessoa. Ligue para o <strong>telefone do cadastro</strong> (aparece na coluna Ação), e não só para o telefone do pedido, que qualquer pessoa pode digitar.</p>
        <?php endif; ?>

        <?php if ($erroDados): ?>
            <div class="alerta alerta-erro" role="alert">Não foi possível carregar os pedidos agora. Se acabou de atualizar o sistema, rode o arquivo banco/atualizacao_v12.sql no MySQL.</div>
        <?php else: ?>

        <?php if ($erro !== ''): ?><div class="alerta alerta-erro" role="alert"><?= e($erro) ?></div><?php endif; ?>
        <?php if ($mensagemAviso !== ''): ?><div class="alerta alerta-info" role="status"><?= e($mensagemAviso) ?></div><?php endif; ?>

        <section class="cartao">
            <h2>Aguardando ligação (<?= count($abertos) ?>)</h2>
            <?php if (!$abertos): ?>
                <p class="vazio">Nenhum pedido em aberto.</p>
            <?php else: ?>
            <div class="tabela-rolagem">
            <table>
                <thead><tr><th>Recebido</th><th>Quem</th><th>Assunto</th><th>Mensagem</th><th>Ação</th></tr></thead>
                <tbody>
                <?php foreach ($abertos as $pedido): ?>
                    <tr>
                        <td><?= e(formatar_data(substr((string) $pedido['data_criacao'], 0, 10))) ?><br><small><?= e(substr((string) $pedido['data_criacao'], 11, 5)) ?></small></td>
                        <td>
                            <?= e($pedido['nome']) ?><?= $pedido['usuario_id'] !== null ? ' <small>(já é cliente)</small>' : '' ?><br>
                            <a class="link" href="tel:<?= e(preg_replace('/[^0-9+]/', '', (string) $pedido['telefone']) ?? '') ?>"><?= e($pedido['telefone']) ?></a>
                            <?php if ($pedido['email'] !== null && $pedido['email'] !== ''): ?><br><small><?= e($pedido['email']) ?></small><?php endif; ?>
                        </td>
                        <td><?= e(AJUDA_ASSUNTOS[$pedido['assunto']] ?? $pedido['assunto']) ?></td>
                        <td><?= $pedido['mensagem'] !== null && $pedido['mensagem'] !== '' ? nl2br(e($pedido['mensagem'])) : '<small>(sem mensagem)</small>' ?></td>
                        <td>
                            <?php if ($pedido['assunto'] === 'Senha'): ?>
                                <?php if (!$ehAdministrador): ?>
                                    <p class="nota">Só o Administrador troca senhas.</p>
                                <?php elseif (!empty($contasDaSenha[(int) $pedido['id']])): $contaDoPedido = $contasDaSenha[(int) $pedido['id']]; ?>
                                    <p class="nota">
                                        Conta: <strong><?= e($contaDoPedido['nome']) ?></strong><br>
                                        Telefone do cadastro: <?= e($contaDoPedido['telefone'] ?? '-') ?>
                                    </p>
                                    <p><a class="link" href="trocar-senha.php?id=<?= (int) $contaDoPedido['id'] ?>">Trocar senha</a></p>
                                <?php else: ?>
                                    <p class="nota">Nenhuma conta com o e-mail informado. Procure em <a class="link" href="clientes.php?q=<?= e(urlencode((string) $pedido['telefone'])) ?>">Clientes</a>.</p>
                                <?php endif; ?>
                            <?php endif; ?>
                            <form method="post" action="pedidos-ajuda.php">
                                <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                                <input type="hidden" name="acao" value="resolver">
                                <input type="hidden" name="id" value="<?= (int) $pedido['id'] ?>">
                                <button type="submit">Marcar como resolvido</button>
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
            <h2>Resolvidos recentemente</h2>
            <?php if (!$resolvidos): ?>
                <p class="vazio">Nenhum pedido resolvido ainda.</p>
            <?php else: ?>
            <div class="tabela-rolagem">
            <table>
                <thead><tr><th>Recebido</th><th>Quem</th><th>Assunto</th><th>Resolvido em</th></tr></thead>
                <tbody>
                <?php foreach ($resolvidos as $pedido): ?>
                    <tr>
                        <td><?= e(formatar_data(substr((string) $pedido['data_criacao'], 0, 10))) ?></td>
                        <td><?= e($pedido['nome']) ?><br><small><?= e($pedido['telefone']) ?></small></td>
                        <td><?= e(AJUDA_ASSUNTOS[$pedido['assunto']] ?? $pedido['assunto']) ?></td>
                        <td><?= $pedido['data_resolucao'] ? e(formatar_data(substr((string) $pedido['data_resolucao'], 0, 10))) : '-' ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            </div>
            <?php endif; ?>
        </section>

        <?php endif; ?>
<?php layout_fechar(); ?>
