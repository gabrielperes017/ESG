<?php
declare(strict_types=1);

require_once __DIR__ . '/ambiente.php';

define('APP_SECRET', (string) configuracao('APP_SECRET', ''));
if (APP_SECRET === '') {
    error_log('Bio Óleo: defina APP_SECRET em config/local.php (modelo: config/local.exemplo.php).');
    http_response_code(500);
    exit('Sistema sem configuração. Avise o administrador.');
}

define('APP_SECRET_ANTERIOR', (string) configuracao('APP_SECRET_ANTERIOR', ''));

define('APP_URL', rtrim((string) configuracao('APP_URL', ''), '/'));

define('GOOGLE_CLIENT_ID',     (string) configuracao('GOOGLE_CLIENT_ID', ''));
define('GOOGLE_CLIENT_SECRET', (string) configuracao('GOOGLE_CLIENT_SECRET', ''));

define('GOOGLE_REDIRECT_URI', (string) configuracao('GOOGLE_REDIRECT_URI', ''));
