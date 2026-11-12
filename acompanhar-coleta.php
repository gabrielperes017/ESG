<?php
declare(strict_types=1);

require __DIR__ . '/config/database.php';
require __DIR__ . '/includes/sessao.php';
require __DIR__ . '/includes/layout.php';

exigir_login();
exigir_perfil(['Cliente'], 'acompanhar');

$usuario = usuario_logado();
$erro = '';
$mensagemAviso   = $_SESSION['flash'] ?? '';
unset($_SESSION['flash']);

$lista = [];
$erroDados = false;

try {
    $pdo = conectar();

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!csrf_valido($_POST['csrf'] ?? null)) {
            $erro = 'A página expirou. Tente novamente.';
        } elseif (($_POST['acao'] ?? '') === 'cancelar') {
            $consulta = $pdo->prepare(
                "UPDATE coletas SET status = 'Cancelada'
                  WHERE id = :id AND cliente_id = :c AND status IN ('Pendente', 'Agendada')"
            );
            $consulta->execute([':id' => (int) ($_POST['id'] ?? 0), ':c' => $usuario['id']]);
            if ($consulta->rowCount() === 1) {
                $_SESSION['flash'] = 'Solicitação cancelada.';
                header('Location: acompanhar-coleta.php');
                exit;
            }
            $erro = 'Não foi possível cancelar: a coleta já foi concluída ou não existe.';
        }
    }

    $consulta = $pdo->prepare(
        'SELECT id, data_agendada, quantidade_litros, status, data_criacao
           FROM coletas WHERE cliente_id = :c ORDER BY data_criacao DESC, id DESC LIMIT 50'
    );
    $consulta->execute([':c' => $usuario['id']]);
    $lista = $consulta->fetchAll();
} catch (PDOException $excecao) {
    registrar_erro_banco('Acompanhar coleta', $excecao);
    $erroDados = true;
}

function andamento(string $status): string
{
    return match ($status) {
        'Pendente'  => 'Aguardando a equipe confirmar a data.',
        'Agendada'  => 'Coleta confirmada. Deixe o óleo pronto na data combinada.',
        'Concluida' => 'Óleo coletado. Obrigado por reciclar!',
        'Cancelada' => 'Solicitação cancelada.',
        default     => '',
    };
}

layout_abrir('Acompanhar coleta', 'acompanhar');
?>
        <h1>Acompanhar coleta</h1>
        <p class="texto-suave">Veja o andamento das suas solicitações de coleta.</p>

        <?php if ($erroDados): ?>
            <div class="alerta alerta-erro" role="alert">Não foi possível carregar suas coletas agora. Atualize em instantes.</div>
        <?php else: ?>

        <?php if ($erro !== ''): ?><div class="alerta alerta-erro" role="alert"><?= e($erro) ?></div><?php endif; ?>
        <?php if ($mensagemAviso !== ''): ?><div class="alerta alerta-info" role="status"><?= e($mensagemAviso) ?></div><?php endif; ?>

        <section class="cartao">
            <div class="cartao-cabeca">
                <h2>Minhas coletas</h2>
                <a class="link" href="solicitar-coleta.php">+ Nova solicitação</a>
            </div>

            <?php if (!$lista): ?>
                <p class="vazio">Você ainda não solicitou nenhuma coleta.</p>
            <?php else: ?>
            <div class="tabela-rolagem">
            <table>
                <thead><tr><th>Solicitada em</th><th>Data</th><th>Quantidade</th><th>Status</th><th>Andamento</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($lista as $coleta): ?>
                    <tr>
                        <td><?= e(formatar_data(substr((string) $coleta['data_criacao'], 0, 10))) ?></td>
                        <td><?= e(formatar_data($coleta['data_agendada'])) ?></td>
                        <td><?= e(formatar_litros($coleta['quantidade_litros'])) ?></td>
                        <td><?= badge_status($coleta['status']) ?></td>
                        <td><?= e(andamento($coleta['status'])) ?></td>
                        <td>
                            <?php if (in_array($coleta['status'], ['Pendente', 'Agendada'], true)): ?>
                            <form method="post" action="acompanhar-coleta.php" class="form-inline"
                                  data-confirmar="Cancelar esta coleta?">
                                <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                                <input type="hidden" name="acao" value="cancelar">
                                <input type="hidden" name="id" value="<?= (int) $coleta['id'] ?>">
                                <button type="submit" class="botao-pequeno botao-sair">Cancelar</button>
                            </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            </div>
            <?php endif; ?>
        </section>

        <?php endif; ?>
<?php layout_fechar(); ?>
