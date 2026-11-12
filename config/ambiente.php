<?php
declare(strict_types=1);

function configuracao(string $nome, mixed $valorPadrao = ''): mixed
{
    static $valoresDoArquivoLocal = null;

    $valorDoAmbiente = getenv('BIOOLEO_' . $nome);
    if ($valorDoAmbiente !== false && $valorDoAmbiente !== '') {
        return $valorDoAmbiente;
    }

    if ($valoresDoArquivoLocal === null) {
        $caminhoDoArquivo  = __DIR__ . '/local.php';
        $conteudoDoArquivo = is_file($caminhoDoArquivo) ? require $caminhoDoArquivo : [];
        $valoresDoArquivoLocal = is_array($conteudoDoArquivo) ? $conteudoDoArquivo : [];
    }

    return $valoresDoArquivoLocal[$nome] ?? $valorPadrao;
}

function requisicao_local(): bool
{
    return in_array((string) ($_SERVER['REMOTE_ADDR'] ?? ''), ['127.0.0.1', '::1'], true);
}

function depuracao_ativa(): bool
{
    return filter_var(configuracao('DEPURACAO', false), FILTER_VALIDATE_BOOLEAN) && requisicao_local();
}

function requisicao_https(): bool
{
    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
        return true;
    }

    $confiaNoProxy = filter_var(configuracao('CONFIAR_PROXY_HTTPS', false), FILTER_VALIDATE_BOOLEAN);
    return $confiaNoProxy && (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
}
