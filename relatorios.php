<?php
declare(strict_types=1);

require __DIR__ . '/config/database.php';
require __DIR__ . '/includes/sessao.php';
require __DIR__ . '/includes/layout.php';

exigir_login();
exigir_perfil(['Administrador'], 'relatorios');

$erroDados = false;
$relatorio = [];

try {
    $pdo = conectar();
    $hoje   = new DateTimeImmutable('today');
    $limite = $hoje->modify('-6 months')->format('Y-m-d');
    $inicioGrafico = $hoje->modify('first day of this month')->modify('-5 months');

    $relatorio['clientes'] = (int) $pdo->query(
        "SELECT COUNT(*) FROM usuarios u JOIN perfis_acesso p ON p.id = u.perfil_id WHERE p.nome = 'Cliente'"
    )->fetchColumn();

    $totais = $pdo->query(
        "SELECT
            COALESCE(SUM(CASE WHEN status = 'Concluida' THEN quantidade_litros END), 0) AS litros,
            COALESCE(SUM(CASE WHEN status = 'Concluida' THEN 1 ELSE 0 END), 0)         AS realizadas,
            COALESCE(SUM(CASE WHEN status = 'Pendente'  THEN 1 ELSE 0 END), 0)         AS pendentes,
            COALESCE(SUM(CASE WHEN status = 'Cancelada' THEN 1 ELSE 0 END), 0)         AS canceladas
           FROM coletas"
    )->fetch();
    $relatorio['litros']     = (float) $totais['litros'];
    $relatorio['realizadas'] = (int) $totais['realizadas'];
    $relatorio['pendentes']  = (int) $totais['pendentes'];
    $relatorio['canceladas'] = (int) $totais['canceladas'];

    $relatorio['reciclado'] = (float) $pdo->query(
        "SELECT COALESCE(SUM(c.quantidade_litros), 0) FROM coletas c
          WHERE c.status = 'Concluida'
            AND EXISTS (SELECT 1 FROM transportes t WHERE t.coleta_id = c.id AND t.status_entrega = 'Entregue')"
    )->fetchColumn();

    $relatorio['aguardando'] = $pdo->query(
        "SELECT u.nome, c.data_criacao
           FROM coletas c JOIN usuarios u ON u.id = c.cliente_id
          WHERE c.status = 'Pendente' ORDER BY c.data_criacao ASC LIMIT 50"
    )->fetchAll();

    $consulta = $pdo->prepare(
        "SELECT u.nome, MAX(c.data_agendada) AS ultima
           FROM usuarios u
           JOIN perfis_acesso p ON p.id = u.perfil_id
           LEFT JOIN coletas c ON c.cliente_id = u.id AND c.status = 'Concluida'
          WHERE p.nome = 'Cliente' AND u.status_conta = 'Ativa'
          GROUP BY u.id, u.nome
         HAVING MAX(c.data_agendada) IS NULL OR MAX(c.data_agendada) < :limite
          ORDER BY ultima ASC, u.nome ASC LIMIT 50"
    );
    $consulta->execute([':limite' => $limite]);
    $relatorio['sem_coleta'] = $consulta->fetchAll();

    $relatorio['por_cliente'] = $pdo->query(
        "SELECT u.nome, COUNT(c.id) AS coletas, MAX(c.data_agendada) AS ultima,
                COALESCE(SUM(c.quantidade_litros), 0) AS litros
           FROM usuarios u
           JOIN perfis_acesso p ON p.id = u.perfil_id
           LEFT JOIN coletas c ON c.cliente_id = u.id AND c.status = 'Concluida'
          WHERE p.nome = 'Cliente'
          GROUP BY u.id, u.nome
          ORDER BY litros DESC, u.nome ASC LIMIT 50"
    )->fetchAll();

    $relatorio['por_data'] = $pdo->query(
        "SELECT data_agendada, SUM(quantidade_litros) AS litros
           FROM coletas WHERE status = 'Concluida'
          GROUP BY data_agendada ORDER BY data_agendada DESC LIMIT 15"
    )->fetchAll();

    $meses = [];
    $nomesMes = ['', 'Jan', 'Fev', 'Mar', 'Abr', 'Mai', 'Jun', 'Jul', 'Ago', 'Set', 'Out', 'Nov', 'Dez'];
    for ($indice = 0; $indice < 6; $indice++) {
        $mes = $inicioGrafico->modify("+$indice months");
        $meses[$mes->format('Y-m')] = ['rotulo' => $nomesMes[(int) $mes->format('n')], 'litros' => 0.0];
    }
    $consulta = $pdo->prepare(
        "SELECT data_agendada, quantidade_litros FROM coletas
          WHERE status = 'Concluida' AND data_agendada >= :ini"
    );
    $consulta->execute([':ini' => $inicioGrafico->format('Y-m-d')]);
    foreach ($consulta->fetchAll() as $linha) {
        $chave = substr((string) $linha['data_agendada'], 0, 7);
        if (isset($meses[$chave])) {
            $meses[$chave]['litros'] += (float) $linha['quantidade_litros'];
        }
    }
    $relatorio['meses'] = array_values($meses);
    $relatorio['max_mes'] = max(array_column($relatorio['meses'], 'litros'));
} catch (PDOException $excecao) {
    registrar_erro_banco('Relatórios', $excecao);
    $erroDados = true;
}

function aguardando(?string $dataSql): string
{
    if (!$dataSql) { return '-'; }
    $dias = (int) (new DateTimeImmutable('today'))->diff(new DateTimeImmutable(substr($dataSql, 0, 10)))->days;
    return $dias === 0 ? 'Hoje' : $dias . ($dias === 1 ? ' dia' : ' dias');
}

function fechar_relatorio(): void { echo '<a class="fechar" href="#">Fechar</a>'; }

layout_abrir('Relatórios', 'relatorios');
?>
        <h1><?= icone('grafico') ?> Relatórios</h1>
        <p class="subtitulo">Acompanhe os dados e tome decisões mais conscientes.</p>

        <?php if ($erroDados): ?>
            <div class="alerta alerta-erro" role="alert">Não foi possível carregar os relatórios agora. Atualize a página em instantes.</div>
        <?php else: ?>

        <section class="colunas">
            <div class="coluna">
                <div class="coluna-topo">
                    <div class="circulo"><?= icone('usuario') ?></div>
                    <div><h2>Clientes</h2><p>Acompanhe a carteira e encontre novas oportunidades de coleta.</p></div>
                </div>
                <ul>
                    <li><a href="#total-clientes"><span class="ico"><?= icone('usuario') ?></span>Total de clientes cadastrados</a></li>
                    <li><a href="#aguardando-retorno"><span class="ico"><?= icone('relogio') ?></span>Clientes aguardando retorno</a></li>
                    <li><a href="#sem-coleta"><span class="ico"><?= icone('calendario') ?></span>Clientes sem coleta há 6 meses</a></li>
                    <li><a href="#historico-clientes"><span class="ico"><?= icone('certificados') ?></span>Histórico de clientes</a></li>
                </ul>
            </div>

            <div class="coluna">
                <div class="coluna-topo">
                    <div class="circulo"><?= icone('meus-pontos') ?></div>
                    <div><h2>Coletas</h2><p>Os dados mais importantes para a operação da Bio Óleo.</p></div>
                </div>
                <ul>
                    <li><a href="#total-litros"><span class="ico"><?= icone('caminhao') ?></span>Total de litros coletados</a></li>
                    <li><a href="#litros-data"><span class="ico"><?= icone('calendario') ?></span>Litros coletados por data</a></li>
                    <li><a href="#realizadas"><span class="ico"><?= icone_cor('confirmado') ?></span>Coletas realizadas</a></li>
                    <li><a href="#pendentes"><span class="ico"><?= icone_cor('pendente') ?></span>Coletas pendentes</a></li>
                    <li><a href="#canceladas"><span class="ico"><?= icone('cancelado') ?></span>Coletas canceladas</a></li>
                    <li><a href="#coletas-cliente"><span class="ico"><?= icone('trofeu') ?></span>Coletas por cliente</a></li>
                </ul>
            </div>

            <div class="coluna">
                <div class="coluna-topo">
                    <div class="circulo"><?= icone_cor('logo-folha', 'ic-cor') ?></div>
                    <div><h2>Sustentabilidade</h2><p>Conectam o sistema ao objetivo ambiental da Bio Óleo (ESG).</p></div>
                </div>
                <ul>
                    <li><a href="#reciclado"><span class="ico"><?= icone('atualizar') ?></span>Total de óleo reciclado</a></li>
                    <li><a href="#evolucao"><span class="ico"><?= icone('grafico') ?></span>Evolução da coleta</a></li>
                    <li><a href="#ranking"><span class="ico"><?= icone('trofeu') ?></span>Ranking de clientes colaboradores</a></li>
                </ul>
            </div>
        </section>

        <p class="dica">Clique em um relatório para ver os detalhes aqui.</p>

        <section class="detalhe" id="total-clientes"><div class="cartao">
            <h2>Total de clientes cadastrados</h2>
            <p class="descricao">Quantidade total de clientes no sistema.</p>
            <p class="numero"><?= $relatorio['clientes'] ?></p>
            <?php fechar_relatorio(); ?>
        </div></section>

        <section class="detalhe" id="aguardando-retorno"><div class="cartao">
            <h2>Clientes aguardando retorno</h2>
            <p class="descricao">Clientes que solicitaram coleta e ainda não receberam uma resposta.</p>
            <div class="tabela-rolagem"><table>
                <thead><tr><th>Cliente</th><th>Solicitado em</th><th>Aguardando</th></tr></thead>
                <tbody>
                <?php if (!$relatorio['aguardando']): ?><tr><td colspan="3" class="vazio">Nenhum cliente aguardando.</td></tr>
                <?php else: foreach ($relatorio['aguardando'] as $linha): ?>
                    <tr><td><?= e($linha['nome']) ?></td><td><?= e(formatar_data(substr((string) $linha['data_criacao'], 0, 10))) ?></td><td><?= e(aguardando($linha['data_criacao'])) ?></td></tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table></div>
            <?php fechar_relatorio(); ?>
        </div></section>

        <section class="detalhe" id="sem-coleta"><div class="cartao">
            <h2>Clientes sem coleta há 6 meses</h2>
            <p class="descricao">Clientes ativos que estão há pelo menos 6 meses sem uma coleta concluída (ou que nunca coletaram).</p>
            <div class="tabela-rolagem"><table>
                <thead><tr><th>Cliente</th><th>Última coleta</th></tr></thead>
                <tbody>
                <?php if (!$relatorio['sem_coleta']): ?><tr><td colspan="2" class="vazio">Nenhum cliente nessa situação.</td></tr>
                <?php else: foreach ($relatorio['sem_coleta'] as $linha): ?>
                    <tr><td><?= e($linha['nome']) ?></td><td><?= $linha['ultima'] ? e(formatar_data($linha['ultima'])) : 'Nunca coletou' ?></td></tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table></div>
            <?php fechar_relatorio(); ?>
        </div></section>

        <section class="detalhe" id="historico-clientes"><div class="cartao">
            <h2>Histórico de clientes</h2>
            <p class="descricao">Quantidade de coletas concluídas, última data e litros coletados por cliente.</p>
            <div class="tabela-rolagem"><table>
                <thead><tr><th>Cliente</th><th>Coletas</th><th>Última coleta</th><th>Litros</th></tr></thead>
                <tbody>
                <?php if (!$relatorio['por_cliente']): ?><tr><td colspan="4" class="vazio">Nenhum cliente cadastrado.</td></tr>
                <?php else: foreach ($relatorio['por_cliente'] as $linha): ?>
                    <tr><td><?= e($linha['nome']) ?></td><td><?= (int) $linha['coletas'] ?></td><td><?= e(formatar_data($linha['ultima'])) ?></td><td><?= e(formatar_litros($linha['litros'])) ?></td></tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table></div>
            <?php fechar_relatorio(); ?>
        </div></section>

        <section class="detalhe" id="total-litros"><div class="cartao">
            <h2>Total de litros coletados</h2>
            <p class="descricao">Quantidade total de óleo das coletas concluídas.</p>
            <p class="numero"><?= e(formatar_litros($relatorio['litros'])) ?></p>
            <?php fechar_relatorio(); ?>
        </div></section>

        <section class="detalhe" id="litros-data"><div class="cartao">
            <h2>Litros coletados por data</h2>
            <p class="descricao">Litros das coletas concluídas, nas 15 datas mais recentes.</p>
            <div class="tabela-rolagem"><table>
                <thead><tr><th>Data</th><th>Litros</th></tr></thead>
                <tbody>
                <?php if (!$relatorio['por_data']): ?><tr><td colspan="2" class="vazio">Nenhuma coleta concluída ainda.</td></tr>
                <?php else: foreach ($relatorio['por_data'] as $linha): ?>
                    <tr><td><?= e(formatar_data($linha['data_agendada'])) ?></td><td><?= e(formatar_litros($linha['litros'])) ?></td></tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table></div>
            <?php fechar_relatorio(); ?>
        </div></section>

        <section class="detalhe" id="realizadas"><div class="cartao">
            <h2>Coletas realizadas</h2>
            <p class="descricao">Quantidade de coletas concluídas.</p>
            <p class="numero"><?= $relatorio['realizadas'] ?></p>
            <?php fechar_relatorio(); ?>
        </div></section>

        <section class="detalhe" id="pendentes"><div class="cartao">
            <h2>Coletas pendentes</h2>
            <p class="descricao">Solicitações que ainda não foram atendidas.</p>
            <p class="numero pendente"><?= $relatorio['pendentes'] ?></p>
            <?php fechar_relatorio(); ?>
        </div></section>

        <section class="detalhe" id="canceladas"><div class="cartao">
            <h2>Coletas canceladas</h2>
            <p class="descricao">Quantidade de coletas que foram canceladas.</p>
            <p class="numero cancelado"><?= $relatorio['canceladas'] ?></p>
            <?php fechar_relatorio(); ?>
        </div></section>

        <section class="detalhe" id="coletas-cliente"><div class="cartao">
            <h2>Coletas por cliente</h2>
            <p class="descricao">Clientes que mais forneceram óleo.</p>
            <div class="tabela-rolagem"><table>
                <thead><tr><th>Cliente</th><th>Coletas</th><th>Litros</th></tr></thead>
                <tbody>
                <?php if (!$relatorio['por_cliente']): ?><tr><td colspan="3" class="vazio">Nenhum cliente cadastrado.</td></tr>
                <?php else: foreach ($relatorio['por_cliente'] as $linha): ?>
                    <tr><td><?= e($linha['nome']) ?></td><td><?= (int) $linha['coletas'] ?></td><td><?= e(formatar_litros($linha['litros'])) ?></td></tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table></div>
            <?php fechar_relatorio(); ?>
        </div></section>

        <section class="detalhe" id="reciclado"><div class="cartao">
            <h2>Total de óleo reciclado</h2>
            <p class="descricao">Óleo de coletas concluídas cujo transporte já foi entregue ao destino.</p>
            <p class="numero"><?= e(formatar_litros($relatorio['reciclado'])) ?></p>
            <?php fechar_relatorio(); ?>
        </div></section>

        <section class="detalhe" id="evolucao"><div class="cartao">
            <h2>Evolução da coleta</h2>
            <p class="descricao">Litros coletados (coletas concluídas) nos últimos 6 meses.</p>
            <div class="grafico">
                <?php foreach ($relatorio['meses'] as $mes):
                    $altura = $relatorio['max_mes'] > 0 ? max(3, (int) round($mes['litros'] / $relatorio['max_mes'] * 100)) : 3; ?>
                    <div class="barra" style="height: <?= $altura ?>%"><?= $mes['litros'] > 0 ? e(formatar_litros($mes['litros'])) : '' ?></div>
                <?php endforeach; ?>
            </div>
            <div class="meses">
                <?php foreach ($relatorio['meses'] as $mes): ?><span><?= e($mes['rotulo']) ?></span><?php endforeach; ?>
            </div>
            <?php fechar_relatorio(); ?>
        </div></section>

        <section class="detalhe" id="ranking"><div class="cartao">
            <h2>Ranking de clientes colaboradores</h2>
            <p class="descricao">Clientes que mais contribuíram com a destinação correta do óleo (top 10).</p>
            <div class="tabela-rolagem"><table>
                <thead><tr><th>#</th><th>Cliente</th><th>Litros</th></tr></thead>
                <tbody>
                <?php $posicao = 0; foreach (array_slice($relatorio['por_cliente'], 0, 10) as $linha): if ((float) $linha['litros'] <= 0) { continue; } $posicao++; ?>
                    <tr><td><?= $posicao ?>º</td><td><?= e($linha['nome']) ?></td><td><?= e(formatar_litros($linha['litros'])) ?></td></tr>
                <?php endforeach; if ($posicao === 0): ?>
                    <tr><td colspan="3" class="vazio">Ainda não há coletas concluídas.</td></tr>
                <?php endif; ?>
                </tbody>
            </table></div>
            <?php fechar_relatorio(); ?>
        </div></section>

        <section class="rodape">
            <span class="folha"><?= icone_cor('logo-folha', 'ic-folha') ?></span>
            <div><h3>Bio Óleo</h3><p>Transformando óleo usado em um futuro mais verde.</p></div>
        </section>

        <?php endif; ?>
<?php layout_fechar(); ?>
