<?php
declare(strict_types=1);

const ENDERECO_CAMPOS_OBRIGATORIOS = ['cep', 'logradouro', 'numero', 'bairro', 'cidade', 'uf'];

const ENDERECO_ESTADOS = [
    'AC' => 'Acre', 'AL' => 'Alagoas', 'AP' => 'Amapá', 'AM' => 'Amazonas', 'BA' => 'Bahia',
    'CE' => 'Ceará', 'DF' => 'Distrito Federal', 'ES' => 'Espírito Santo', 'GO' => 'Goiás',
    'MA' => 'Maranhão', 'MT' => 'Mato Grosso', 'MS' => 'Mato Grosso do Sul', 'MG' => 'Minas Gerais',
    'PA' => 'Pará', 'PB' => 'Paraíba', 'PR' => 'Paraná', 'PE' => 'Pernambuco', 'PI' => 'Piauí',
    'RJ' => 'Rio de Janeiro', 'RN' => 'Rio Grande do Norte', 'RS' => 'Rio Grande do Sul',
    'RO' => 'Rondônia', 'RR' => 'Roraima', 'SC' => 'Santa Catarina', 'SP' => 'São Paulo',
    'SE' => 'Sergipe', 'TO' => 'Tocantins',
];

function endereco_vazio(?array $linhaDoBanco = null): array
{
    $endereco = [];
    foreach (['cep', 'logradouro', 'numero', 'complemento', 'bairro', 'cidade', 'uf', 'ponto_referencia'] as $campo) {
        $endereco[$campo] = trim((string) ($linhaDoBanco[$campo] ?? ''));
    }
    return $endereco;
}

function endereco_do_formulario(array $post): array
{
    $endereco = endereco_vazio();
    foreach ($endereco as $campo => $_) {
        $valor = preg_replace('/\s+/u', ' ', trim((string) ($post[$campo] ?? ''))) ?? '';
        $endereco[$campo] = $valor;
    }
    $endereco['cep'] = preg_replace('/\D/', '', $endereco['cep']) ?? '';
    $endereco['uf']  = strtoupper($endereco['uf']);
    return $endereco;
}

function endereco_mensagem_invalida(array $endereco): ?string
{
    if (!preg_match('/^\d{8}$/', $endereco['cep'])) {
        return 'Informe o CEP com 8 números. Exemplo: 87200-000.';
    }
    if (mb_strlen($endereco['logradouro']) < 3 || mb_strlen($endereco['logradouro']) > 150) {
        return 'Informe a rua ou avenida (de 3 a 150 caracteres).';
    }
    if ($endereco['numero'] === '' || mb_strlen($endereco['numero']) > 20) {
        return 'Informe o número do endereço. Se não tiver número, escreva S/N.';
    }
    if (mb_strlen($endereco['complemento']) > 80) {
        return 'O complemento pode ter até 80 caracteres.';
    }
    if (mb_strlen($endereco['bairro']) < 2 || mb_strlen($endereco['bairro']) > 80) {
        return 'Informe o bairro (até 80 caracteres).';
    }
    if (mb_strlen($endereco['cidade']) < 2 || mb_strlen($endereco['cidade']) > 80) {
        return 'Informe a cidade (até 80 caracteres).';
    }
    if (!isset(ENDERECO_ESTADOS[$endereco['uf']])) {
        return 'Escolha o estado (UF).';
    }
    if (mb_strlen($endereco['ponto_referencia']) > 150) {
        return 'O ponto de referência pode ter até 150 caracteres.';
    }
    return null;
}

function endereco_completo(?array $linhaDoBanco): bool
{
    if ($linhaDoBanco === null) {
        return false;
    }
    foreach (ENDERECO_CAMPOS_OBRIGATORIOS as $campo) {
        if (trim((string) ($linhaDoBanco[$campo] ?? '')) === '') {
            return false;
        }
    }
    return true;
}

function endereco_cep_formatado(string $cep): string
{
    $digitos = preg_replace('/\D/', '', $cep) ?? '';
    return strlen($digitos) === 8 ? substr($digitos, 0, 5) . '-' . substr($digitos, 5) : $digitos;
}

function endereco_texto(?array $linhaDoBanco): string
{
    if (!endereco_completo($linhaDoBanco)) {
        return '';
    }
    $complemento = trim((string) ($linhaDoBanco['complemento'] ?? ''));
    return $linhaDoBanco['logradouro'] . ', ' . $linhaDoBanco['numero']
        . ($complemento !== '' ? ' - ' . $complemento : '')
        . ' - ' . $linhaDoBanco['bairro']
        . ' - ' . $linhaDoBanco['cidade'] . '/' . $linhaDoBanco['uf']
        . ' - CEP ' . endereco_cep_formatado((string) $linhaDoBanco['cep']);
}

function endereco_link_mapa(?array $linhaDoBanco): string
{
    if (!endereco_completo($linhaDoBanco)) {
        return '';
    }
    $busca = $linhaDoBanco['logradouro'] . ', ' . $linhaDoBanco['numero'] . ', ' . $linhaDoBanco['bairro']
        . ', ' . $linhaDoBanco['cidade'] . ' - ' . $linhaDoBanco['uf'] . ', ' . endereco_cep_formatado((string) $linhaDoBanco['cep']);
    return 'https://www.google.com/maps/search/?api=1&query=' . rawurlencode($busca);
}

function endereco_colunas_sql(string $prefixo = 'u'): string
{
    $colunas = [];
    foreach (['cep', 'logradouro', 'numero', 'complemento', 'bairro', 'cidade', 'uf', 'ponto_referencia'] as $campo) {
        $colunas[] = $prefixo . '.' . $campo;
    }
    return implode(', ', $colunas);
}

function endereco_bloco_html(array $linhaDoBanco): string
{
    if (!endereco_completo($linhaDoBanco)) {
        return '<small class="endereco-falta">Endereço não informado: ligue para o cliente</small>';
    }
    $html = '<small class="endereco">' . e(endereco_texto($linhaDoBanco)) . '</small>';
    $referencia = trim((string) ($linhaDoBanco['ponto_referencia'] ?? ''));
    if ($referencia !== '') {
        $html .= '<br><small class="endereco-referencia">Ref.: ' . e($referencia) . '</small>';
    }
    $html .= '<br><a class="link" href="' . e(endereco_link_mapa($linhaDoBanco)) . '" target="_blank" rel="noopener noreferrer">Ver no mapa</a>';
    return $html;
}
