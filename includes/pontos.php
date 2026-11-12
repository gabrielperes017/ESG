<?php
declare(strict_types=1);

const PONTOS_POR_LITRO = 1;

function pontos_extrato(PDO $pdo, int $clienteId): array
{
    $consulta = $pdo->prepare(
        "SELECT c.id, c.data_agendada, c.quantidade_litros
           FROM coletas c
          WHERE c.cliente_id = :id AND c.status = 'Concluida'
            AND EXISTS (SELECT 1 FROM transportes t WHERE t.coleta_id = c.id AND t.status_entrega = 'Entregue')
          ORDER BY c.data_agendada DESC, c.id DESC"
    );
    $consulta->execute([':id' => $clienteId]);

    $ganhos = [];
    $total  = 0;
    foreach ($consulta->fetchAll() as $linha) {
        $pontos = (int) floor((float) $linha['quantidade_litros']) * PONTOS_POR_LITRO;
        $ganhos[] = $linha + ['pontos' => $pontos];
        $total += $pontos;
    }

    $consulta = $pdo->prepare("SELECT COALESCE(SUM(pontos), 0) FROM resgates WHERE cliente_id = :id AND status <> 'Cancelado'");
    $consulta->execute([':id' => $clienteId]);
    $gastos = (int) $consulta->fetchColumn();

    return ['ganhos' => $ganhos, 'total' => $total, 'gastos' => $gastos, 'saldo' => $total - $gastos];
}

function pontos_saldo(PDO $pdo, int $clienteId): ?int
{
    try {
        return pontos_extrato($pdo, $clienteId)['saldo'];
    } catch (PDOException $excecao) {
        registrar_erro_banco('Pontos', $excecao);
        return null;
    }
}
