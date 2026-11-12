<?php
declare(strict_types=1);

require __DIR__ . '/config/database.php';
require __DIR__ . '/includes/sessao.php';
require __DIR__ . '/includes/layout.php';
require __DIR__ . '/includes/certificados.php';

exigir_login();
exigir_perfil(['Cliente', 'Administrador'], 'certificados');

$usuario = usuario_logado();
$ehCliente = $usuario['perfil'] === 'Cliente';
$lista = [];
$erroDados = false;

try {
    $pdo = conectar();
    $sql = "SELECT c.id, c.quantidade_litros, c.data_agendada, u.nome AS cliente, MAX(t.data_transporte) AS entrega
              FROM coletas c
              JOIN usuarios u ON u.id = c.cliente_id
              JOIN transportes t ON t.coleta_id = c.id AND t.status_entrega = 'Entregue'
             WHERE c.status = 'Concluida'" . ($ehCliente ? ' AND c.cliente_id = :cli' : '') . '
             GROUP BY c.id, c.quantidade_litros, c.data_agendada, u.nome
             ORDER BY c.data_agendada DESC, c.id DESC LIMIT 100';
    $consulta = $pdo->prepare($sql);
    $consulta->execute($ehCliente ? [':cli' => $usuario['id']] : []);
    $lista = $consulta->fetchAll();
} catch (PDOException $excecao) {
    registrar_erro_banco('Certificados', $excecao);
    $erroDados = true;
}

layout_abrir('Certificados', 'certificados');
?>
        <h1>Certificados de destinação ambiental</h1>
        <p class="texto-suave">
            <?= $ehCliente
                ? 'Comprove que o seu óleo usado teve o destino correto. O certificado fica disponível quando o óleo é entregue ao destino final.'
                : 'Certificados de todas as coletas com óleo já entregue ao destino final.' ?>
        </p>

        <?php if ($erroDados): ?>
            <div class="alerta alerta-erro" role="alert">Não foi possível carregar os certificados agora. Atualize em instantes.</div>
        <?php else: ?>

        <section class="cartao">
            <div class="tabela-rolagem">
            <table>
                <thead>
                    <tr>
                        <?php if (!$ehCliente): ?><th>Cliente</th><?php endif; ?>
                        <th>Coleta</th><th>Entrega ao destino</th><th>Quantidade</th><th>Código</th><th></th>
                    </tr>
                </thead>
                <tbody>
                <?php if (!$lista): ?>
                    <tr><td class="vazio" colspan="<?= $ehCliente ? 5 : 6 ?>">
                        <?= $ehCliente ? 'Você ainda não tem certificados. Eles aparecem depois que o óleo coletado é entregue ao destino.' : 'Nenhum certificado disponível ainda.' ?>
                    </td></tr>
                <?php else: foreach ($lista as $certificado): ?>
                    <tr>
                        <?php if (!$ehCliente): ?><td><?= e($certificado['cliente']) ?></td><?php endif; ?>
                        <td><?= e(formatar_data($certificado['data_agendada'])) ?></td>
                        <td><?= e(formatar_data($certificado['entrega'])) ?></td>
                        <td><?= e(formatar_litros($certificado['quantidade_litros'])) ?></td>
                        <td><code><?= e(codigo_certificado((int) $certificado['id'])) ?></code></td>
                        <td><a class="link" href="certificado.php?id=<?= (int) $certificado['id'] ?>" target="_blank" rel="noopener">Ver / imprimir</a></td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
            </div>
            <p class="nota">Qualquer pessoa pode conferir a autenticidade de um certificado em
                <a class="link" href="validar-certificado.php" target="_blank" rel="noopener">validar-certificado.php</a>, informando o código.</p>
        </section>

        <?php endif; ?>
<?php layout_fechar(); ?>
