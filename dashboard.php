<?php
declare(strict_types=1);

require __DIR__ . '/config/database.php';
require __DIR__ . '/includes/sessao.php';
require __DIR__ . '/includes/layout.php';
require __DIR__ . '/includes/pontos.php';

exigir_login();

$usuario  = usuario_logado();
$ehCliente = $usuario['perfil'] === 'Cliente';
$primeiroNome = explode(' ', trim($usuario['nome']))[0];

$erroDados = false;
$cards     = [];
$ultimas   = [];
$proxima   = null;
$litros    = 0.0;

try {
    $pdo = conectar();

    if ($ehCliente) {
        $consulta = $pdo->prepare(
            "SELECT data_agendada, status FROM coletas
              WHERE cliente_id = :id AND status IN ('Agendada', 'Pendente') AND data_agendada >= CURDATE()
              ORDER BY data_agendada ASC LIMIT 1"
        );
        $consulta->execute([':id' => $usuario['id']]);
        $proxima = $consulta->fetch() ?: null;

        $consulta = $pdo->prepare(
            "SELECT COALESCE(SUM(quantidade_litros), 0) FROM coletas
              WHERE cliente_id = :id AND status = 'Concluida'"
        );
        $consulta->execute([':id' => $usuario['id']]);
        $litros = (float) $consulta->fetchColumn();

        $consulta = $pdo->prepare(
            "SELECT data_agendada, quantidade_litros, status FROM coletas
              WHERE cliente_id = :id
              ORDER BY data_agendada DESC, id DESC LIMIT 5"
        );
        $consulta->execute([':id' => $usuario['id']]);
        $ultimas = $consulta->fetchAll();
    } else {
        $consulta = $pdo->prepare(
            "SELECT
               (SELECT COUNT(*) FROM usuarios u JOIN perfis_acesso p ON p.id = u.perfil_id
                 WHERE p.nome = 'Cliente' AND u.status_conta = 'Ativa')               AS clientes,
               (SELECT COUNT(*) FROM coletas WHERE status IN ('Agendada', 'Pendente')) AS a_realizar,
               (SELECT COUNT(*) FROM coletas WHERE status = 'Concluida')              AS concluidas,
               (SELECT COALESCE(SUM(quantidade_litros), 0) FROM coletas
                 WHERE status = 'Concluida')                                           AS litros"
        );
        $consulta->execute();
        $resumo = $consulta->fetch();

        $cards = [
            ['calendario', 'Coletas a realizar', (string) (int) $resumo['a_realizar'], 'Agendadas e pendentes'],
            ['concluido',  'Coletas concluídas', (string) (int) $resumo['concluidas'], ''],
            ['gota',       'Óleo coletado',      formatar_litros($resumo['litros']),    'Somente coletas concluídas'],
            ['usuarios',   'Clientes ativos',    (string) (int) $resumo['clientes'],    ''],
        ];

        $consulta = $pdo->prepare(
            "SELECT c.data_agendada, c.quantidade_litros, c.status, u.nome AS cliente
               FROM coletas c JOIN usuarios u ON u.id = c.cliente_id
              ORDER BY c.data_agendada DESC, c.id DESC LIMIT 5"
        );
        $consulta->execute();
        $ultimas = $consulta->fetchAll();
    }
} catch (PDOException $excecao) {
    registrar_erro_banco('Dashboard', $excecao);
    $erroDados = true;
}

if ($ehCliente && !$erroDados) {
    $cards = [
        [
            'calendario', 'Próxima coleta',
            $proxima ? formatar_data($proxima['data_agendada']) : 'Nenhuma',
            $proxima ? $proxima['status'] : 'Peça uma coleta quando precisar',
        ],
        ['gota', 'Óleo reciclado', formatar_litros($litros), 'Somente coletas concluídas'],
    ];
    $pontos = pontos_saldo(conectar(), $usuario['id']);
    if ($pontos !== null) {
        $cards[] = ['gota', 'Meus pontos', (string) $pontos, 'Troque por vantagens em Pontos e vantagens'];
    }
}

layout_abrir('Início', 'inicio');
?>
        <h1>Olá, <?= e($primeiroNome) ?>!</h1>
        <p class="texto-suave">Juntos por um futuro mais sustentável.</p>

        <?php if ($erroDados): ?>
            <div class="alerta alerta-erro" role="alert">Não foi possível carregar os dados agora. Atualize a página em instantes.</div>
        <?php else: ?>

        <section class="resumo" aria-label="Resumo">
            <?php foreach ($cards as [$icone, $rotulo, $valor, $nota]): ?>
                <div class="cartao">
                    <h3><?= e($rotulo) ?></h3>
                    <p class="numero"><?= e($valor) ?></p>
                    <?php if ($nota !== ''): ?><p class="nota"><?= e($nota) ?></p><?php endif; ?>
                </div>
            <?php endforeach; ?>
        </section>

        <?php if ($ehCliente): ?>
        <div class="botoes">
            <?php if (pagina_pronta('solicitar-coleta.php')): ?>
                <a class="botao-solicitar" href="solicitar-coleta.php">Solicitar coleta</a>
            <?php else: ?>
                <span class="botao-solicitar desativado" aria-disabled="true" title="Em desenvolvimento">Solicitar coleta</span>
            <?php endif; ?>

            <?php if (pagina_pronta('acompanhar-coleta.php')): ?>
                <a class="botao-acompanhar" href="acompanhar-coleta.php">Acompanhar coleta</a>
            <?php else: ?>
                <span class="botao-acompanhar desativado" aria-disabled="true" title="Em desenvolvimento">Acompanhar coleta</span>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <div>
            <section class="cartao">
                <div class="cartao-cabeca">
                    <h2>Últimas coletas</h2>
                    <?php if ($ehCliente && pagina_pronta('acompanhar-coleta.php')): ?>
                        <a class="link" href="acompanhar-coleta.php">Ver todas</a>
                    <?php elseif (!$ehCliente && perfil_pode($usuario['perfil'], 'ver_agendamentos')): ?>
                        <a class="link" href="agendamentos.php">Ver todas</a>
                    <?php endif; ?>
                </div>

                <div class="tabela-rolagem">
                    <table>
                        <thead>
                            <tr>
                                <?php if (!$ehCliente): ?><th scope="col">Cliente</th><?php endif; ?>
                                <th scope="col">Data</th>
                                <th scope="col">Quantidade</th>
                                <th scope="col">Status</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php if (!$ultimas): ?>
                            <tr><td class="vazio" colspan="<?= $ehCliente ? 3 : 4 ?>">
                                <?= $ehCliente ? 'Você ainda não tem coletas. Solicite a primeira quando quiser.' : 'Nenhuma coleta registrada ainda.' ?>
                            </td></tr>
                        <?php else: foreach ($ultimas as $coleta): ?>
                            <tr>
                                <?php if (!$ehCliente): ?><td><?= e($coleta['cliente']) ?></td><?php endif; ?>
                                <td><?= e(formatar_data($coleta['data_agendada'])) ?></td>
                                <td><?= e(formatar_litros($coleta['quantidade_litros'])) ?></td>
                                <td><?= badge_status($coleta['status']) ?></td>
                            </tr>
                        <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
            </section>

            <?php if ($ehCliente): ?>
            <section class="cartao">
                <h2>Impacto ambiental</h2>
                <p class="numero"><?= e(formatar_litros($litros)) ?></p>
                <p class="nota">Óleo reciclado. O cálculo de CO₂ evitado ficará disponível nos relatórios.</p>
            </section>
            <?php endif; ?>
        </div>

        <?php if ($ehCliente): ?>
            <section class="rodape">
                <div class="folha"><?= icone_cor('logo-folha', 'ic-folha') ?></div>
                <div><h3>Pequenas atitudes fazem uma grande diferença!</h3><p>Obrigado por reciclar com a Bio Óleo.</p></div>
            </section>
        <?php endif; ?>

        <?php endif; ?>
<?php layout_fechar(); ?>
