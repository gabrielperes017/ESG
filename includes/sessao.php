<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/ambiente.php';
require_once __DIR__ . '/endereco.php';
require_once __DIR__ . '/erros_banco.php';

const SESSAO_INATIVIDADE_MAXIMA = 1800;
const SESSAO_DURACAO_MAXIMA     = 28800;

if (!depuracao_ativa()) {
    ini_set('display_errors', '0');
}

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=(), usb=()');
header('Cross-Origin-Opener-Policy: same-origin');
header('Cache-Control: no-store');

header(
    "Content-Security-Policy: default-src 'self'; script-src 'self'; "
    . "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; "
    . "font-src 'self' https://fonts.gstatic.com; img-src 'self' data:; connect-src 'self'; "
    . "object-src 'none'; base-uri 'self'; form-action 'self'; frame-ancestors 'self'"
);

if (requisicao_https()) {
    header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
}

if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');

    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => requisicao_https(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

function e(string $texto): string
{
    return htmlspecialchars($texto, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_valido(mixed $token): bool
{
    return is_string($token)
        && isset($_SESSION['csrf'])
        && hash_equals($_SESSION['csrf'], $token);
}

function usuario_logado(): ?array
{
    return $_SESSION['usuario'] ?? null;
}

function registrar_login(array $usuarioDoBanco): void
{
    session_regenerate_id(true);
    unset($_SESSION['csrf']);
    $_SESSION['login_em']      = time();
    $_SESSION['ultimo_acesso'] = time();
    $_SESSION['usuario'] = [
        'id'        => (int) $usuarioDoBanco['id'],
        'nome'      => $usuarioDoBanco['nome'],
        'email'     => $usuarioDoBanco['email'],
        'perfil_id' => (int) $usuarioDoBanco['perfil_id'],
        'perfil'    => $usuarioDoBanco['perfil'],
    ];
}

function encerrar_sessao(): void
{
    $_SESSION = [];

    if (ini_get('session.use_cookies')) {
        $parametrosDoCookie = session_get_cookie_params();
        setcookie(session_name(), '', [
            'expires'  => time() - 3600,
            'path'     => $parametrosDoCookie['path'],
            'domain'   => $parametrosDoCookie['domain'],
            'secure'   => $parametrosDoCookie['secure'],
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }

    session_destroy();
}

function exigir_login(): void
{
    $usuario = usuario_logado();

    if ($usuario === null) {
        header('Location: login.php');
        exit;
    }

    $agora          = time();
    $loginEm        = (int) ($_SESSION['login_em'] ?? $agora);
    $inativoHaSegundos = $agora - (int) ($_SESSION['ultimo_acesso'] ?? $agora);

    if ($inativoHaSegundos > SESSAO_INATIVIDADE_MAXIMA || $agora - $loginEm > SESSAO_DURACAO_MAXIMA) {
        encerrar_sessao();
        header('Location: login.php?expirou=1');
        exit;
    }
    $_SESSION['login_em']      = $loginEm;
    $_SESSION['ultimo_acesso'] = $agora;

    require_once __DIR__ . '/../config/database.php';

    try {
        $consulta = conectar()->prepare(
            'SELECT u.nome, u.perfil_id, u.status_conta, p.nome AS perfil
               FROM usuarios u JOIN perfis_acesso p ON p.id = u.perfil_id
              WHERE u.id = :usuario_id LIMIT 1'
        );
        $consulta->execute([':usuario_id' => $usuario['id']]);
        $contaAtual = $consulta->fetch();
    } catch (PDOException $excecao) {
        registrar_erro_banco('Sessão', $excecao);
        return;
    }

    if (!$contaAtual || $contaAtual['status_conta'] !== 'Ativa') {
        encerrar_sessao();
        header('Location: login.php');
        exit;
    }

    $_SESSION['usuario']['nome']      = $contaAtual['nome'];
    $_SESSION['usuario']['perfil_id'] = (int) $contaAtual['perfil_id'];
    $_SESSION['usuario']['perfil']    = $contaAtual['perfil'];

    if ($contaAtual['perfil'] === 'Cliente') {
        try {
            $consulta = conectar()->prepare('SELECT ' . endereco_colunas_sql('u') . ' FROM usuarios u WHERE u.id = :usuario_id LIMIT 1');
            $consulta->execute([':usuario_id' => $usuario['id']]);
            $enderecoAtual = $consulta->fetch() ?: null;
        } catch (PDOException $excecao) {
            registrar_erro_banco('Endereço', $excecao);
            http_response_code(503);
            $detalhe = depuracao_ativa() && banco_desatualizado()
                ? '<p><strong>Aviso para quem cuida do sistema:</strong> ' . htmlspecialchars(BANCO_DESATUALIZADO_AVISO, ENT_QUOTES, 'UTF-8') . '</p>'
                : '';
            exit('<!doctype html><html lang="pt-BR"><meta charset="utf-8"><title>Sistema em atualização</title>'
                . '<body style="font-family:Georgia,serif;max-width:560px;margin:60px auto;padding:0 16px">'
                . '<h1>Sistema em atualização</h1><p>Não foi possível abrir sua conta agora. Tente de novo em alguns minutos '
                . 'ou fale com a equipe da Bio Óleo.</p>' . $detalhe . '<p><a href="login.php">Voltar ao login</a></p></body></html>');
        }

        $paginaAtual = basename((string) ($_SERVER['SCRIPT_NAME'] ?? ''));
        if (!endereco_completo($enderecoAtual) && !in_array($paginaAtual, ['perfil.php', 'logout.php', 'ajuda.php'], true)) {
            $_SESSION['flash'] = 'Antes de continuar, informe o seu endereço de coleta. É por ele que a nossa equipe encontra o óleo.';
            header('Location: perfil.php?endereco=1#endereco');
            exit;
        }
    }
}
