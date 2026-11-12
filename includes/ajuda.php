<?php
declare(strict_types=1);

const LIMITE_PEDIDOS_AJUDA_POR_IP = 5;

const AJUDA_ASSUNTOS = [
    'Cadastro'    => 'Não consigo criar minha conta',
    'Senha'       => 'Esqueci minha senha',
    'Acesso'      => 'Não consigo entrar na minha conta',
    'Agendamento' => 'Não consigo pedir uma coleta',
    'Outro'       => 'Outra dúvida',
];

function ajuda_link_whatsapp(): string
{
    $numero = preg_replace('/\D/', '', (string) configuracao('CONTATO_WHATSAPP', '')) ?? '';
    if (strlen($numero) < 10 || strlen($numero) > 15) {
        return '';
    }
    return 'https://wa.me/' . $numero . '?text=' . rawurlencode('Olá! Preciso de ajuda com o Bio Óleo.');
}

function ajuda_telefone_exibicao(): string
{
    return trim((string) configuracao('CONTATO_TELEFONE', ''));
}

function ajuda_ler_formulario(array $post): array
{
    $limpar = static fn (string $valor): string => preg_replace('/[ \t]+/u', ' ', trim($valor)) ?? '';
    return [
        'nome'     => $limpar((string) ($post['nome'] ?? '')),
        'telefone' => $limpar((string) ($post['telefone'] ?? '')),
        'email'    => $limpar((string) ($post['email'] ?? '')),
        'assunto'  => (string) ($post['assunto'] ?? ''),
        'mensagem' => trim((string) ($post['mensagem'] ?? '')),
    ];
}

function ajuda_mensagem_invalida(array $pedido): ?string
{
    if ($pedido['nome'] === '' || mb_strlen($pedido['nome']) > 150) {
        return 'Informe o seu nome.';
    }
    if (!preg_match('/^[0-9()+\-\s]{8,20}$/', $pedido['telefone']) || !in_array(strlen(telefone_digitos($pedido['telefone'])), [10, 11], true)) {
        return 'Informe um telefone com DDD, para a gente ligar. Exemplo: (44) 99999-0000.';
    }
    if ($pedido['email'] !== '' && (strlen($pedido['email']) > 100 || !filter_var($pedido['email'], FILTER_VALIDATE_EMAIL))) {
        return 'O e-mail informado não parece certo. Pode deixar em branco se preferir.';
    }
    if (!isset(AJUDA_ASSUNTOS[$pedido['assunto']])) {
        return 'Escolha com o que você precisa de ajuda.';
    }
    if ($pedido['assunto'] === 'Senha' && $pedido['email'] === '') {
        return 'Para achar a sua conta, informe o e-mail que você usou no cadastro.';
    }
    if (mb_strlen($pedido['mensagem']) > 1000) {
        return 'A mensagem pode ter até 1000 caracteres.';
    }
    return null;
}
