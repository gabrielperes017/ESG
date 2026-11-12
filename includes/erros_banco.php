<?php
declare(strict_types=1);

function banco_desatualizado(?bool $definir = null): bool
{
    static $valor = false;
    if ($definir !== null) {
        $valor = $definir;
    }
    return $valor;
}

function erro_de_banco_desatualizado(PDOException $excecao): bool
{
    $codigo   = (string) $excecao->getCode();
    $mensagem = $excecao->getMessage();
    return in_array($codigo, ['42S22', '42S02'], true)
        || stripos($mensagem, 'Unknown column') !== false
        || stripos($mensagem, "doesn't exist") !== false
        || stripos($mensagem, 'no such column') !== false
        || stripos($mensagem, 'no such table') !== false;
}

function registrar_erro_banco(string $contexto, PDOException $excecao): void
{
    error_log($contexto . ': ' . $excecao->getMessage());
    if (erro_de_banco_desatualizado($excecao)) {
        banco_desatualizado(true);
    }
}

const BANCO_DESATUALIZADO_AVISO = 'Falta atualizar o banco de dados. No MySQL Workbench, abra e rode os arquivos da pasta banco/ que você ainda não rodou (atualizacao_v11.sql e atualizacao_v12.sql) e recarregue esta página.';
