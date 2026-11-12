<?php
declare(strict_types=1);

function icone(string $nome): string
{
    $nome = preg_replace('/[^a-z0-9-]/', '', $nome);
    return '<span class="ic i-' . $nome . '" aria-hidden="true"></span>';
}

function icone_cor(string $nome, string $classe = 'ic-cor'): string
{
    $nome = preg_replace('/[^a-z0-9-]/', '', $nome);
    return '<img class="' . $classe . '" src="assets/icones/' . $nome . '.svg" alt="" aria-hidden="true">';
}

function perfil_pode(string $perfil, string $acao): bool
{
    if ($perfil === 'Administrador') {
        return true;
    }
    return match ($acao) {
        'ver_agendamentos' => in_array($perfil, ['Operação', 'Visualizar'], true),
        'agendar'          => $perfil === 'Operação',
        'registrar_coleta' => $perfil === 'Operação',
        default            => false,
    };
}

function itens_menu(string $perfil): array
{
    $inicio = ['rotulo' => 'Início', 'arquivo' => 'dashboard.php', 'icone' => 'inicio', 'chave' => 'inicio'];
    $perfilItem = ['rotulo' => 'Perfil', 'arquivo' => 'perfil.php', 'icone' => 'usuario', 'chave' => 'perfil'];
    $agenda = ['rotulo' => 'Agendamentos', 'arquivo' => 'agendamentos.php', 'icone' => 'calendario', 'chave' => 'agendamentos'];

    return match ($perfil) {
        'Administrador' => [
            $inicio,
            ['rotulo' => 'Clientes',     'arquivo' => 'clientes.php',   'icone' => 'usuario',      'chave' => 'clientes'],
            $agenda,
            ['rotulo' => 'Coletas',      'arquivo' => 'coletas.php',    'icone' => 'caminhao',     'chave' => 'coletas'],
            ['rotulo' => 'Certificados', 'arquivo' => 'certificados.php', 'icone' => 'certificados', 'chave' => 'certificados'],
            ['rotulo' => 'Pontos e vantagens', 'arquivo' => 'vantagens.php', 'icone' => 'meus-pontos', 'chave' => 'vantagens'],
            ['rotulo' => 'Relatórios',   'arquivo' => 'relatorios.php', 'icone' => 'grafico', 'chave' => 'relatorios'],
            ['rotulo' => 'Pedidos de ajuda', 'arquivo' => 'pedidos-ajuda.php', 'icone' => 'notificacoes', 'chave' => 'pedidos-ajuda'],
            ['rotulo' => 'Restrição de acesso', 'arquivo' => 'acesso.php', 'icone' => 'perfil',    'chave' => 'acesso'],
            $perfilItem,
        ],
        'Operação' => [
            $inicio,
            $agenda,
            ['rotulo' => 'Coletas', 'arquivo' => 'coletas.php', 'icone' => 'caminhao', 'chave' => 'coletas'],
            ['rotulo' => 'Pedidos de ajuda', 'arquivo' => 'pedidos-ajuda.php', 'icone' => 'notificacoes', 'chave' => 'pedidos-ajuda'],
            $perfilItem,
        ],
        'Visualizar' => [$inicio, $agenda, $perfilItem],
        'Cliente' => [
            $inicio,
            ['rotulo' => 'Solicitar coleta',  'arquivo' => 'solicitar-coleta.php',  'icone' => 'solicitar-coleta',  'chave' => 'solicitar'],
            ['rotulo' => 'Acompanhar coleta', 'arquivo' => 'acompanhar-coleta.php', 'icone' => 'acompanhar-coleta', 'chave' => 'acompanhar'],
            ['rotulo' => 'Certificados', 'arquivo' => 'certificados.php', 'icone' => 'certificados', 'chave' => 'certificados'],
            ['rotulo' => 'Pontos e vantagens', 'arquivo' => 'pontos.php', 'icone' => 'meus-pontos', 'chave' => 'pontos'],
            $perfilItem,
            ['rotulo' => 'Ajuda', 'arquivo' => 'ajuda.php', 'icone' => 'pesquisa', 'chave' => 'ajuda'],
        ],
        default => [$inicio, $perfilItem],
    };
}
