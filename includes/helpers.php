<?php
declare(strict_types=1);

function formatar_litros(float|string|null $valor): string
{
    $texto = number_format((float) $valor, 2, ',', '.');
    $texto = rtrim(rtrim($texto, '0'), ',');
    return $texto . ' L';
}

function formatar_data(?string $dataSql): string
{
    $data = $dataSql ? DateTime::createFromFormat('Y-m-d', $dataSql) : false;
    return $data ? $data->format('d/m/Y') : '-';
}

function badge_status(string $status): string
{
    $mapa = [
        'Agendada'  => ['Agendada',  'badge-agendada'],
        'Pendente'  => ['Pendente',  'badge-pendente'],
        'Concluida' => ['Concluída', 'badge-concluida'],
        'Cancelada' => ['Cancelada', 'badge-cancelada'],
    ];
    [$rotulo, $classe] = $mapa[$status] ?? [$status, 'badge-cancelada'];

    return '<span class="badge ' . $classe . '">' . e($rotulo) . '</span>';
}

function telefone_digitos(string $telefone): string
{
    $digitos = preg_replace('/\D/', '', $telefone) ?? '';

    if (strlen($digitos) > 11 && str_starts_with($digitos, '55')) {
        $digitos = substr($digitos, 2);
    }

    return $digitos;
}

function telefone_formatado(string $digitos): string
{
    if (strlen($digitos) === 11) {
        return sprintf('(%s) %s-%s', substr($digitos, 0, 2), substr($digitos, 2, 5), substr($digitos, 7));
    }
    if (strlen($digitos) === 10) {
        return sprintf('(%s) %s-%s', substr($digitos, 0, 2), substr($digitos, 2, 4), substr($digitos, 6));
    }
    return $digitos;
}

function sql_telefone_digitos(string $coluna): string
{
    return "REPLACE(REPLACE(REPLACE(REPLACE(REPLACE($coluna, '(', ''), ')', ''), '-', ''), ' ', ''), '+', '')";
}

function pagina_pronta(string $arquivo): bool
{
    return is_file(__DIR__ . '/../' . $arquivo);
}

function telefone_ja_usado(PDO $pdo, string $digitos, int $ignorarId = 0): bool
{
    $consulta = $pdo->prepare(
        'SELECT COUNT(*) FROM usuarios WHERE id <> :id AND ' . sql_telefone_digitos('telefone') . ' IN (:t1, :t2)'
    );
    $consulta->execute([':id' => $ignorarId, ':t1' => $digitos, ':t2' => '55' . $digitos]);
    return (int) $consulta->fetchColumn() > 0;
}

function badge_resgate(string $status): string
{
    $mapa = [
        'Solicitado' => ['Solicitado', 'badge-pendente'],
        'Entregue'   => ['Entregue',   'badge-concluida'],
        'Cancelado'  => ['Cancelado',  'badge-cancelada'],
    ];
    [$rotulo, $classe] = $mapa[$status] ?? [$status, 'badge-cancelada'];
    return '<span class="badge ' . $classe . '">' . e($rotulo) . '</span>';
}

const SENHA_MINIMA = 8;
const SENHA_MAXIMA = 72;

const SENHAS_MUITO_COMUNS = [
    '12345678', '123456789', '1234567890', '11111111', '00000000', 'password', 'password1',
    'qwertyui', 'qwerty123', 'abcd1234', 'senha123', 'senha1234', 'mudar123', 'brasil123',
];

function mensagem_senha_invalida(string $senha, string $email = '', string $sujeito = 'A senha'): ?string
{
    if (strlen($senha) < SENHA_MINIMA) {
        return $sujeito . ' precisa ter pelo menos ' . SENHA_MINIMA . ' caracteres.';
    }
    if (strlen($senha) > SENHA_MAXIMA) {
        return $sujeito . ' pode ter no máximo ' . SENHA_MAXIMA . ' caracteres.';
    }
    if (in_array(mb_strtolower($senha), SENHAS_MUITO_COMUNS, true)) {
        return 'Essa senha é muito comum e fácil de adivinhar. Escolha outra.';
    }
    if ($email !== '' && mb_strtolower($senha) === mb_strtolower($email)) {
        return $sujeito . ' não pode ser igual ao e-mail.';
    }
    return null;
}
