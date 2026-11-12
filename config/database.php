<?php
declare(strict_types=1);

require_once __DIR__ . '/ambiente.php';

define('DB_HOST',    (string) configuracao('DB_HOST', '127.0.0.1'));
define('DB_PORT',    (int) configuracao('DB_PORT', 3306));
define('DB_NAME',    (string) configuracao('DB_NAME', 'biooleo_esg'));
define('DB_USER',    (string) configuracao('DB_USER', ''));
define('DB_PASS',    (string) configuracao('DB_PASS', ''));
define('DB_CHARSET', 'utf8mb4');

function conectar(): PDO
{
    static $conexao = null;

    if ($conexao === null) {
        $enderecoDoBanco = 'mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET;
        $conexao = new PDO($enderecoDoBanco, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::ATTR_TIMEOUT            => 5,
        ]);
    }

    return $conexao;
}
