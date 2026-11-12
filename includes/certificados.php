<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/app.php';

function codigo_certificado(int $coletaId, string $chaveSecreta = APP_SECRET): string
{
    $assinatura = strtoupper(substr(hash_hmac('sha256', 'certificado:' . $coletaId, $chaveSecreta), 0, 6));
    return 'BIO-' . str_pad((string) $coletaId, 6, '0', STR_PAD_LEFT) . '-' . $assinatura;
}

function coleta_do_codigo(string $codigo): ?int
{
    $codigo = strtoupper(trim($codigo));
    if (!preg_match('/^BIO-(\d{6})-([0-9A-F]{6})$/', $codigo, $partes)) {
        return null;
    }
    $coletaId = (int) $partes[1];

    $chavesAceitas = array_filter([APP_SECRET, APP_SECRET_ANTERIOR], static fn (string $chave): bool => $chave !== '');
    foreach ($chavesAceitas as $chave) {
        if (hash_equals(codigo_certificado($coletaId, $chave), $codigo)) {
            return $coletaId;
        }
    }
    return null;
}

function certificado_dados(PDO $pdo, int $coletaId): array|false
{
    $consulta = $pdo->prepare(
        "SELECT c.id, c.quantidade_litros, c.data_agendada, u.id AS cliente_id, u.nome AS cliente, u.cargo,
                t.data_transporte
           FROM coletas c
           JOIN usuarios u ON u.id = c.cliente_id
           JOIN transportes t ON t.coleta_id = c.id AND t.status_entrega = 'Entregue'
          WHERE c.id = :id AND c.status = 'Concluida'
          ORDER BY t.id DESC LIMIT 1"
    );
    $consulta->execute([':id' => $coletaId]);
    return $consulta->fetch();
}

function nome_mascarado(string $nome): string
{
    $partes = preg_split('/\s+/', trim($nome)) ?: [];
    $partesMascaradas = [];
    foreach ($partes as $posicao => $parte) {
        $partesMascaradas[] = $posicao === 0 || mb_strlen($parte) <= 2 ? $parte : mb_substr($parte, 0, 1) . '***' . mb_substr($parte, -1);
    }
    return implode(' ', $partesMascaradas);
}
