<?php
declare(strict_types=1);

const LIMITE_JANELA_SEGUNDOS = 900;

const LIMITE_LOGIN_POR_CONTA          = 5;
const LIMITE_LOGIN_POR_IP             = 20;
const LIMITE_VALIDACAO_CERTIFICADO_IP = 30;
const LIMITE_TROCA_SENHA_POR_CONTA    = 5;

function endereco_ip_do_visitante(): string
{
    return (string) ($_SERVER['REMOTE_ADDR'] ?? 'desconhecido');
}

function limite_chave(string $escopo, string $identificador): string
{
    return hash('sha256', $escopo . '|' . mb_strtolower(trim($identificador)));
}

function limite_segundos_restantes(PDO $conexao, string $escopo, string $identificador): int
{
    try {
        $consulta = $conexao->prepare('SELECT bloqueado_ate FROM tentativas_acesso WHERE chave = :chave');
        $consulta->execute([':chave' => limite_chave($escopo, $identificador)]);
        return max(0, (int) $consulta->fetchColumn() - time());
    } catch (PDOException $excecao) {
        registrar_erro_banco('Limite de tentativas (rode banco/atualizacao_v10.sql)', $excecao);
        return 0;
    }
}

function limite_registrar_falha(PDO $conexao, string $escopo, string $identificador, int $maximoDeFalhas): void
{
    $chave = limite_chave($escopo, $identificador);
    $agora = time();

    try {
        $consulta = $conexao->prepare('SELECT falhas, janela_inicio FROM tentativas_acesso WHERE chave = :chave');
        $consulta->execute([':chave' => $chave]);
        $registro = $consulta->fetch();

        if ($registro === false) {
            try {
                $conexao->prepare(
                    'INSERT INTO tentativas_acesso (chave, falhas, janela_inicio, bloqueado_ate) VALUES (:chave, 1, :agora, 0)'
                )->execute([':chave' => $chave, ':agora' => $agora]);
                return;
            } catch (PDOException $excecao) {
                if ($excecao->getCode() !== '23000') { throw $excecao; }
                $consulta->execute([':chave' => $chave]);
                $registro = $consulta->fetch();
                if ($registro === false) { return; }
            }
        }

        $janelaVenceu = $agora - (int) $registro['janela_inicio'] > LIMITE_JANELA_SEGUNDOS;
        $falhas       = $janelaVenceu ? 1 : (int) $registro['falhas'] + 1;
        $janelaInicio = $janelaVenceu ? $agora : (int) $registro['janela_inicio'];
        $bloqueadoAte = $falhas >= $maximoDeFalhas ? $agora + LIMITE_JANELA_SEGUNDOS : 0;

        $conexao->prepare(
            'UPDATE tentativas_acesso SET falhas = :falhas, janela_inicio = :janela_inicio, bloqueado_ate = :bloqueado_ate
              WHERE chave = :chave'
        )->execute([':falhas' => $falhas, ':janela_inicio' => $janelaInicio, ':bloqueado_ate' => $bloqueadoAte, ':chave' => $chave]);

        if (random_int(1, 100) === 1) {
            $conexao->prepare('DELETE FROM tentativas_acesso WHERE janela_inicio < :limite AND bloqueado_ate < :agora')
                    ->execute([':limite' => $agora - 2 * LIMITE_JANELA_SEGUNDOS, ':agora' => $agora]);
        }
    } catch (PDOException $excecao) {
        registrar_erro_banco('Limite de tentativas (rode banco/atualizacao_v10.sql)', $excecao);
    }
}

function limite_zerar(PDO $conexao, string $escopo, string $identificador): void
{
    try {
        $conexao->prepare('DELETE FROM tentativas_acesso WHERE chave = :chave')
                ->execute([':chave' => limite_chave($escopo, $identificador)]);
    } catch (PDOException $excecao) {
        registrar_erro_banco('Limite de tentativas', $excecao);
    }
}

function limite_mensagem(int $segundosRestantes): string
{
    $minutos = max(1, (int) ceil($segundosRestantes / 60));
    return 'Muitas tentativas seguidas. Tente de novo em ' . $minutos . ($minutos === 1 ? ' minuto.' : ' minutos.');
}
