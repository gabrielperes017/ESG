<?php
declare(strict_types=1);

require __DIR__ . '/config/database.php';
require __DIR__ . '/includes/sessao.php';
require __DIR__ . '/includes/layout.php';

exigir_login();
exigir_perfil(['Administrador'], 'clientes');

const POR_PAGINA = 15;

$erro = '';
$mensagemAviso   = $_SESSION['flash'] ?? '';
unset($_SESSION['flash']);

$busca  = trim((string) ($_GET['q'] ?? ''));
$pagina = max(1, (int) ($_GET['p'] ?? 1));
$total  = 0;
$lista  = [];
$erroDados = false;

try {
    $pdo = conectar();

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!csrf_valido($_POST['csrf'] ?? null)) {
            $erro = 'A página expirou. Tente novamente.';
        } elseif (($_POST['acao'] ?? '') === 'conta') {
            $novo = ($_POST['ativar'] ?? '') === '1' ? 'Ativa' : 'Inativa';
            $atualizacao = $pdo->prepare(
                "UPDATE usuarios SET status_conta = :s
                  WHERE id = :id AND status_conta <> :s2 AND perfil_id = (SELECT id FROM perfis_acesso WHERE nome = 'Cliente')"
            );
            $atualizacao->execute([':s' => $novo, ':s2' => $novo, ':id' => (int) ($_POST['id'] ?? 0)]);
            if ($atualizacao->rowCount() === 1) {
                $_SESSION['flash'] = $novo === 'Ativa' ? 'Conta ativada.' : 'Conta desativada: o cliente não consegue mais entrar.';
                header('Location: clientes.php' . ($busca !== '' ? '?q=' . urlencode($busca) : ''));
                exit;
            }
            $erro = 'Nada foi alterado: o cliente não existe ou já estava nessa situação.';
        }
    }

    $onde   = "p.nome = 'Cliente'";
    $parametros = [];
    if ($busca !== '') {
        $like = '%' . addcslashes($busca, '%_\\') . '%';
        $onde .= ' AND (u.nome LIKE :q1 OR u.email LIKE :q2 OR u.telefone LIKE :q3';
        $parametros = [':q1' => $like, ':q2' => $like, ':q3' => $like];

        $digitosBusca = telefone_digitos($busca);
        if (strlen($digitosBusca) >= 4) {
            $onde .= ' OR ' . sql_telefone_digitos('u.telefone') . ' LIKE :q4';
            $parametros[':q4'] = '%' . $digitosBusca . '%';
        }
        $onde .= ')';
    }

    $consulta = $pdo->prepare("SELECT COUNT(*) FROM usuarios u JOIN perfis_acesso p ON p.id = u.perfil_id WHERE $onde");
    $consulta->execute($parametros);
    $total   = (int) $consulta->fetchColumn();
    $paginas = max(1, (int) ceil($total / POR_PAGINA));
    $pagina  = min($pagina, $paginas);

    $consulta = $pdo->prepare(
        "SELECT u.id, u.nome, u.email, u.telefone, u.cargo, u.status_conta, u.data_cadastro, " . endereco_colunas_sql('u') . ",
                COUNT(c.id) AS coletas, COALESCE(SUM(c.quantidade_litros), 0) AS litros
           FROM usuarios u
           JOIN perfis_acesso p ON p.id = u.perfil_id
           LEFT JOIN coletas c ON c.cliente_id = u.id AND c.status = 'Concluida'
          WHERE $onde
          GROUP BY u.id, u.nome, u.email, u.telefone, u.cargo, u.status_conta, u.data_cadastro, " . endereco_colunas_sql('u') . "
          ORDER BY u.nome ASC
          LIMIT " . POR_PAGINA . ' OFFSET ' . (($pagina - 1) * POR_PAGINA)
    );
    $consulta->execute($parametros);
    $lista = $consulta->fetchAll();
} catch (PDOException $excecao) {
    registrar_erro_banco('Clientes', $excecao);
    $erroDados = true;
    $paginas = 1;
}

function link_pagina(int $numeroPagina, string $busca): string
{
    return 'clientes.php?p=' . $numeroPagina . ($busca !== '' ? '&q=' . urlencode($busca) : '');
}

layout_abrir('Clientes', 'clientes');
?>
        <h1>Clientes</h1>
        <p class="texto-suave">Consulte os clientes cadastrados, ative ou desative contas e troque a senha de quem pediu ajuda.</p>

        <?php if ($erroDados): ?>
            <div class="alerta alerta-erro" role="alert">Não foi possível carregar os clientes agora. Atualize em instantes.</div>
        <?php else: ?>

        <?php if ($erro !== ''): ?><div class="alerta alerta-erro" role="alert"><?= e($erro) ?></div><?php endif; ?>
        <?php if ($mensagemAviso !== ''): ?><div class="alerta alerta-info" role="status"><?= e($mensagemAviso) ?></div><?php endif; ?>

        <section class="cartao">
            <form method="get" action="clientes.php" class="busca">
                <label for="q" class="sr-only">Buscar cliente</label>
                <input type="search" id="q" name="q" value="<?= e($busca) ?>" placeholder="Buscar por nome, e-mail ou telefone">
                <button type="submit">Buscar</button>
                <?php if ($busca !== ''): ?><a class="link" href="clientes.php">Limpar</a><?php endif; ?>
            </form>
            <p class="nota"><?= $total ?> cliente(s) <?= $busca !== '' ? 'encontrado(s)' : 'cadastrado(s)' ?>. Coletas e litros consideram só as coletas concluídas.</p>

            <div class="tabela-rolagem">
            <table>
                <thead><tr><th>Cliente</th><th>Contato</th><th>Perfil</th><th>Endereço de coleta</th><th>Cadastro</th><th>Coletas</th><th>Litros</th><th>Conta</th></tr></thead>
                <tbody>
                <?php if (!$lista): ?>
                    <tr><td class="vazio" colspan="7">Nenhum cliente encontrado.</td></tr>
                <?php else: foreach ($lista as $cliente): $ativa = $cliente['status_conta'] === 'Ativa'; ?>
                    <tr>
                        <td><?= e($cliente['nome']) ?></td>
                        <td><?= e($cliente['email']) ?><br><small><?= e($cliente['telefone'] ?? '-') ?></small></td>
                        <td><?= e($cliente['cargo'] ?? '-') ?></td>
                        <td><?= endereco_bloco_html($cliente) ?></td>
                        <td><?= e(formatar_data($cliente['data_cadastro'])) ?></td>
                        <td><?= (int) $cliente['coletas'] ?></td>
                        <td><?= e(formatar_litros($cliente['litros'])) ?></td>
                        <td>
                            <span class="badge <?= $ativa ? 'badge-concluida' : 'badge-cancelada' ?>"><?= $ativa ? 'Ativa' : 'Inativa' ?></span>
                            <form method="post" action="<?= e(link_pagina($pagina, $busca)) ?>" class="form-inline">
                                <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                                <input type="hidden" name="acao" value="conta">
                                <input type="hidden" name="id" value="<?= (int) $cliente['id'] ?>">
                                <input type="hidden" name="ativar" value="<?= $ativa ? '0' : '1' ?>">
                                <button type="submit" class="botao-pequeno"><?= $ativa ? 'Desativar' : 'Ativar' ?></button>
                            </form>
                            <br><a class="link" href="trocar-senha.php?id=<?= (int) $cliente['id'] ?>">Trocar senha</a>
                        </td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
            </div>

            <?php if ($paginas > 1): ?>
            <nav class="filtros paginacao" aria-label="Páginas">
                <?php if ($pagina > 1): ?><a class="filtro" href="<?= e(link_pagina($pagina - 1, $busca)) ?>">&laquo; Anterior</a><?php endif; ?>
                <span class="filtro ativo">Página <?= $pagina ?> de <?= $paginas ?></span>
                <?php if ($pagina < $paginas): ?><a class="filtro" href="<?= e(link_pagina($pagina + 1, $busca)) ?>">Próxima &raquo;</a><?php endif; ?>
            </nav>
            <?php endif; ?>
        </section>

        <?php endif; ?>
<?php layout_fechar(); ?>
