<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/app.php';

function google_configurado(): bool
{
    return GOOGLE_CLIENT_ID !== '' && GOOGLE_CLIENT_SECRET !== '';
}

function url_base(): string
{
    if (APP_URL !== '') {
        return APP_URL;
    }

    $servidor = (string) ($_SERVER['HTTP_HOST'] ?? '');
    if (!preg_match('/^[a-z0-9]([a-z0-9.-]*[a-z0-9])?(:\d{1,5})?$/i', $servidor)) {
        $servidor = 'localhost';
    }
    $pasta = rtrim(str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '/'))), '/.');
    return (requisicao_https() ? 'https://' : 'http://') . $servidor . $pasta;
}

function google_redirect_uri(): string
{
    return GOOGLE_REDIRECT_URI !== '' ? GOOGLE_REDIRECT_URI : url_base() . '/google-callback.php';
}

function google_token_url(): string
{
    return defined('GOOGLE_TOKEN_URL') ? (string) constant('GOOGLE_TOKEN_URL') : 'https://oauth2.googleapis.com/token';
}

function base64url(string $bytes): string
{
    return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
}

function google_url_autorizacao(string $state, string $nonce, string $verifier): string
{
    return 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query([
        'client_id'             => GOOGLE_CLIENT_ID,
        'redirect_uri'          => google_redirect_uri(),
        'response_type'         => 'code',
        'scope'                 => 'openid email profile',
        'state'                 => $state,
        'nonce'                 => $nonce,
        'code_challenge'        => base64url(hash('sha256', $verifier, true)),
        'code_challenge_method' => 'S256',
        'prompt'                => 'select_account',
    ], '', '&', PHP_QUERY_RFC3986);
}

function google_enviar_formulario(string $url, array $campos): array
{
    $corpoEnviado = http_build_query($campos);

    if (function_exists('curl_init')) {
        $conexaoCurl = curl_init($url);
        curl_setopt_array($conexaoCurl, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $corpoEnviado,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        $resposta = curl_exec($conexaoCurl);
        $status   = (int) curl_getinfo($conexaoCurl, CURLINFO_RESPONSE_CODE);
        $erroCurl = curl_error($conexaoCurl);
        curl_close($conexaoCurl);

        if ($resposta === false) {
            throw new RuntimeException('Não consegui falar com o Google: ' . $erroCurl);
        }
        return [$status, (string) $resposta];
    }

    if (!extension_loaded('openssl')) {
        throw new RuntimeException('Nem cURL nem OpenSSL estão ativados no PHP (php.ini: extension=curl e extension=openssl).');
    }
    $contexto = stream_context_create([
        'http' => [
            'method'        => 'POST',
            'header'        => "Content-Type: application/x-www-form-urlencoded\r\nContent-Length: " . strlen($corpoEnviado) . "\r\n",
            'content'       => $corpoEnviado,
            'timeout'       => 15,
            'ignore_errors' => true,
        ],
        'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
    ]);
    $resposta = @file_get_contents($url, false, $contexto);
    if ($resposta === false) {
        throw new RuntimeException('Não consegui falar com o Google (sem cURL). Verifique a internet e o certificado (openssl.cafile no php.ini).');
    }
    $status = 0;
    foreach ($http_response_header ?? [] as $linhaCabecalho) {
        if (preg_match('#^HTTP/\S+\s+(\d{3})#', $linhaCabecalho, $parte)) {
            $status = (int) $parte[1];
        }
    }
    return [$status, $resposta];
}

function google_trocar_codigo(string $code, string $verifier): array
{
    [$status, $resposta] = google_enviar_formulario(google_token_url(), [
        'code'          => $code,
        'client_id'     => GOOGLE_CLIENT_ID,
        'client_secret' => GOOGLE_CLIENT_SECRET,
        'redirect_uri'  => google_redirect_uri(),
        'grant_type'    => 'authorization_code',
        'code_verifier' => $verifier,
    ]);

    $dados = json_decode((string) $resposta, true);
    if ($status !== 200 || !is_array($dados) || empty($dados['id_token'])) {
        $motivo = is_array($dados) ? (($dados['error'] ?? '') . ' ' . ($dados['error_description'] ?? '')) : 'resposta inválida';
        throw new RuntimeException('O Google recusou o login (HTTP ' . $status . '): ' . trim($motivo));
    }
    return $dados;
}

function google_validar_id_token(string $jwt, string $nonceEsperado): array
{
    $partes = explode('.', $jwt);
    if (count($partes) !== 3) {
        throw new RuntimeException('id_token em formato inválido.');
    }
    $payload = json_decode((string) base64_decode(strtr($partes[1], '-_', '+/'), true), true);
    if (!is_array($payload)) {
        throw new RuntimeException('id_token ilegível.');
    }

    if (!in_array($payload['iss'] ?? '', ['https://accounts.google.com', 'accounts.google.com'], true)) {
        throw new RuntimeException('id_token de emissor desconhecido.');
    }
    $publicoDoToken = $payload['aud'] ?? '';
    if ($publicoDoToken !== GOOGLE_CLIENT_ID && !(is_array($publicoDoToken) && in_array(GOOGLE_CLIENT_ID, $publicoDoToken, true))) {
        throw new RuntimeException('id_token emitido para outro aplicativo.');
    }
    if ((int) ($payload['exp'] ?? 0) < time()) {
        throw new RuntimeException('id_token expirado.');
    }
    if (!isset($payload['nonce']) || !hash_equals($nonceEsperado, (string) $payload['nonce'])) {
        throw new RuntimeException('nonce não confere.');
    }
    $email = (string) ($payload['email'] ?? '');
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 100) {
        throw new RuntimeException('O Google não informou um e-mail válido.');
    }
    if (!in_array($payload['email_verified'] ?? false, [true, 'true'], true)) {
        throw new RuntimeException('O e-mail dessa conta Google não está verificado.');
    }

    return [
        'email' => mb_strtolower($email),
        'nome'  => trim((string) ($payload['name'] ?? '')),
    ];
}
