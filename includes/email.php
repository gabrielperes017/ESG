<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/ambiente.php';

function email_configurado(): bool
{
    return trim((string) configuracao('SMTP_HOST', '')) !== ''
        && filter_var((string) configuracao('EMAIL_REMETENTE', ''), FILTER_VALIDATE_EMAIL) !== false;
}

function email_sem_quebra_de_linha(string $texto): string
{
    return trim(str_replace(["\r", "\n", "\0"], ' ', $texto));
}

function email_cabecalho_codificado(string $texto): string
{
    return '=?UTF-8?B?' . base64_encode(email_sem_quebra_de_linha($texto)) . '?=';
}

function email_ler_resposta($conexao): array
{
    $codigo = 0;
    $texto  = '';
    while (($linha = fgets($conexao, 1024)) !== false) {
        $texto .= $linha;
        $codigo = (int) substr($linha, 0, 3);
        if (strlen($linha) < 4 || $linha[3] !== '-') {
            break;
        }
    }
    return [$codigo, $texto];
}

function email_comando($conexao, string $comando, array $codigosEsperados): string
{
    fwrite($conexao, $comando . "\r\n");
    [$codigo, $texto] = email_ler_resposta($conexao);
    if (!in_array($codigo, $codigosEsperados, true)) {
        throw new RuntimeException('SMTP respondeu ' . $codigo . ' ao comando ' . strtok($comando, ' '));
    }
    return $texto;
}

function email_enviar(string $para, string $assunto, string $texto): bool
{
    $para = email_sem_quebra_de_linha($para);
    if (!email_configurado() || filter_var($para, FILTER_VALIDATE_EMAIL) === false) {
        return false;
    }

    $servidor    = trim((string) configuracao('SMTP_HOST', ''));
    $porta       = (int) configuracao('SMTP_PORT', 587);
    $seguranca   = strtolower((string) configuracao('SMTP_SEGURANCA', 'tls'));
    $usuarioSmtp = (string) configuracao('SMTP_USUARIO', '');
    $senhaSmtp   = (string) configuracao('SMTP_SENHA', '');
    $remetente   = email_sem_quebra_de_linha((string) configuracao('EMAIL_REMETENTE', ''));
    $nomeRemet   = (string) configuracao('EMAIL_REMETENTE_NOME', 'Bio Óleo');
    $servidorLocal = in_array($servidor, ['localhost', '127.0.0.1', '::1'], true);

    if ($seguranca === 'nenhuma' && $usuarioSmtp !== '' && !$servidorLocal) {
        error_log('E-mail: SMTP_SEGURANCA=nenhuma com senha só é aceito para servidor local.');
        return false;
    }

    $conexao = null;
    try {
        $contexto = stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true, 'peer_name' => $servidor]]);
        $destino  = ($seguranca === 'ssl' ? 'ssl://' : 'tcp://') . $servidor . ':' . $porta;
        $conexao  = @stream_socket_client($destino, $numeroErro, $textoErro, 10, STREAM_CLIENT_CONNECT, $contexto);
        if ($conexao === false) {
            throw new RuntimeException('Não conectou ao SMTP: ' . $textoErro);
        }
        stream_set_timeout($conexao, 15);

        [$codigo] = email_ler_resposta($conexao);
        if ($codigo !== 220) {
            throw new RuntimeException('SMTP recusou a conexão (' . $codigo . ').');
        }

        $nomeLocal = 'biooleo.local';
        email_comando($conexao, 'EHLO ' . $nomeLocal, [250]);

        if ($seguranca === 'tls') {
            email_comando($conexao, 'STARTTLS', [220]);
            if (stream_socket_enable_crypto($conexao, true, STREAM_CRYPTO_METHOD_TLS_CLIENT) !== true) {
                throw new RuntimeException('Falha ao ativar a criptografia (STARTTLS).');
            }
            email_comando($conexao, 'EHLO ' . $nomeLocal, [250]);
        }

        if ($usuarioSmtp !== '') {
            email_comando($conexao, 'AUTH LOGIN', [334]);
            email_comando($conexao, base64_encode($usuarioSmtp), [334]);
            email_comando($conexao, base64_encode($senhaSmtp), [235]);
        }

        email_comando($conexao, 'MAIL FROM:<' . $remetente . '>', [250]);
        email_comando($conexao, 'RCPT TO:<' . $para . '>', [250, 251]);
        email_comando($conexao, 'DATA', [354]);

        $dominio  = substr(strrchr($remetente, '@') ?: '@biooleo.local', 1);
        $cabecalhos = [
            'Date: ' . date(DATE_RFC2822),
            'From: ' . email_cabecalho_codificado($nomeRemet) . ' <' . $remetente . '>',
            'To: <' . $para . '>',
            'Subject: ' . email_cabecalho_codificado($assunto),
            'Message-ID: <' . bin2hex(random_bytes(12)) . '@' . $dominio . '>',
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: base64',
        ];
        $mensagem = implode("\r\n", $cabecalhos) . "\r\n\r\n" . chunk_split(base64_encode($texto), 76, "\r\n");
        $mensagem = preg_replace('/^\./m', '..', $mensagem) ?? $mensagem;

        fwrite($conexao, $mensagem . ".\r\n");
        [$codigo] = email_ler_resposta($conexao);
        if ($codigo !== 250) {
            throw new RuntimeException('SMTP não aceitou a mensagem (' . $codigo . ').');
        }
        @fwrite($conexao, "QUIT\r\n");
        return true;
    } catch (Throwable $excecao) {
        error_log('E-mail: ' . $excecao->getMessage());
        return false;
    } finally {
        if (is_resource($conexao)) {
            fclose($conexao);
        }
    }
}
