<?php
declare(strict_types=1);

require __DIR__ . '/config/database.php';
require __DIR__ . '/includes/sessao.php';
require __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/limite_tentativas.php';
require_once __DIR__ . '/includes/endereco.php';

exigir_login();

$usuario = usuario_logado();
$usuarioId      = $usuario['id'];

$erros = ['dados' => '', 'senha' => '', 'endereco' => ''];
$ehCliente = ($usuario['perfil'] ?? '') === 'Cliente';
$enderecoDigitado = null;
$mensagemAviso    = $_SESSION['flash'] ?? '';
unset($_SESSION['flash']);

try {
    $pdo = conectar();

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $acao = (string) ($_POST['acao'] ?? '');

        if (!csrf_valido($_POST['csrf'] ?? null)) {
            $erros['dados'] = 'A página expirou. Tente novamente.';
        } elseif ($acao === 'dados') {
            $nome     = trim((string) ($_POST['nome'] ?? ''));
            $telefone = trim((string) ($_POST['telefone'] ?? ''));

            if ($nome === '' || mb_strlen($nome) > 150) {
                $erros['dados'] = 'Informe seu nome (até 150 caracteres).';
            } elseif ($telefone !== '' && (!preg_match('/^[0-9()+\-\s]{8,20}$/', $telefone) || !in_array(strlen(telefone_digitos($telefone)), [10, 11], true))) {
                $erros['dados'] = 'Telefone inválido. Informe com DDD. Exemplo: (44) 99999-0000.';
            } elseif ($telefone !== '' && telefone_ja_usado($pdo, telefone_digitos($telefone), $usuarioId)) {
                $erros['dados'] = 'Já existe outra conta com esse telefone.';
            } else {
                if ($telefone !== '') { $telefone = telefone_formatado(telefone_digitos($telefone)); }
                $pdo->prepare('UPDATE usuarios SET nome = :n, telefone = :t WHERE id = :id')
                    ->execute([':n' => $nome, ':t' => $telefone !== '' ? $telefone : null, ':id' => $usuarioId]);
                $_SESSION['usuario']['nome'] = $nome;
                $_SESSION['flash'] = 'Dados atualizados com sucesso.';
                header('Location: perfil.php');
                exit;
            }
        } elseif ($acao === 'endereco' && $ehCliente) {
            $enderecoDigitado = endereco_do_formulario($_POST);
            $erroEndereco     = endereco_mensagem_invalida($enderecoDigitado);

            if ($erroEndereco !== null) {
                $erros['endereco'] = $erroEndereco;
            } else {
                $pdo->prepare(
                    'UPDATE usuarios SET cep = :cep, logradouro = :logradouro, numero = :numero, complemento = :complemento,
                            bairro = :bairro, cidade = :cidade, uf = :uf, ponto_referencia = :referencia
                      WHERE id = :id'
                )->execute([
                    ':cep'         => $enderecoDigitado['cep'],
                    ':logradouro'  => $enderecoDigitado['logradouro'],
                    ':numero'      => $enderecoDigitado['numero'],
                    ':complemento' => $enderecoDigitado['complemento'] !== '' ? $enderecoDigitado['complemento'] : null,
                    ':bairro'      => $enderecoDigitado['bairro'],
                    ':cidade'      => $enderecoDigitado['cidade'],
                    ':uf'          => $enderecoDigitado['uf'],
                    ':referencia'  => $enderecoDigitado['ponto_referencia'] !== '' ? $enderecoDigitado['ponto_referencia'] : null,
                    ':id'          => $usuarioId,
                ]);
                $primeiroPreenchimento = ($_POST['completar'] ?? '') === '1';
                $_SESSION['flash'] = $primeiroPreenchimento
                    ? 'Endereço salvo. Agora você já pode usar o sistema.'
                    : 'Endereço de coleta atualizado.';
                header('Location: ' . ($primeiroPreenchimento ? 'dashboard.php' : 'perfil.php#endereco'));
                exit;
            }
        } elseif ($acao === 'senha') {
            $atual     = (string) ($_POST['senha_atual'] ?? '');
            $nova      = (string) ($_POST['senha'] ?? '');
            $confirmar = (string) ($_POST['confirmar'] ?? '');

            $consulta = $pdo->prepare('SELECT senha FROM usuarios WHERE id = :id');
            $consulta->execute([':id' => $usuarioId]);
            $hash = (string) $consulta->fetchColumn();

            $segundosDeEspera = limite_segundos_restantes($pdo, 'troca_senha', (string) $usuarioId);
            $mensagemSenha    = mensagem_senha_invalida($nova, (string) ($usuario['email'] ?? ''), 'A nova senha');

            if ($segundosDeEspera > 0) {
                $erros['senha'] = limite_mensagem($segundosDeEspera);
            } elseif (!password_verify($atual, $hash)) {
                limite_registrar_falha($pdo, 'troca_senha', (string) $usuarioId, LIMITE_TROCA_SENHA_POR_CONTA);
                $erros['senha'] = 'A senha atual está incorreta.';
            } elseif ($mensagemSenha !== null) {
                $erros['senha'] = $mensagemSenha;
            } elseif ($nova !== $confirmar) {
                $erros['senha'] = 'A confirmação não é igual à nova senha.';
            } elseif ($nova === $atual) {
                $erros['senha'] = 'A nova senha precisa ser diferente da atual.';
            } else {
                $pdo->prepare('UPDATE usuarios SET senha = :s WHERE id = :id')
                    ->execute([':s' => password_hash($nova, PASSWORD_DEFAULT), ':id' => $usuarioId]);
                limite_zerar($pdo, 'troca_senha', (string) $usuarioId);
                session_regenerate_id(true);
                $_SESSION['flash'] = 'Senha alterada com sucesso.';
                header('Location: perfil.php');
                exit;
            }
        }
    }

    $consulta = $pdo->prepare(
        'SELECT u.nome, u.email, u.telefone, u.data_cadastro, p.nome AS perfil, ' . endereco_colunas_sql('u') . '
           FROM usuarios u JOIN perfis_acesso p ON p.id = u.perfil_id
          WHERE u.id = :id LIMIT 1'
    );
    $consulta->execute([':id' => $usuarioId]);
    $dados = $consulta->fetch();
    $enderecoPreenchido = $dados ? endereco_completo($dados) : true;
    $enderecoNoFormulario = $enderecoDigitado ?? endereco_vazio($dados ?: null);
} catch (PDOException $excecao) {
    registrar_erro_banco('Perfil', $excecao);
    $dados = false;
    $enderecoPreenchido = true;
    $enderecoNoFormulario = endereco_vazio();
}

layout_abrir('Perfil', 'perfil');
?>
        <h1>Meu perfil</h1>
        <p class="texto-suave">Mantenha seus dados sempre atualizados.</p>

        <?php if (!$dados): ?>
            <div class="alerta alerta-erro" role="alert">Não foi possível carregar seu perfil agora. Atualize a página em instantes.</div>
        <?php else: ?>

        <?php if ($mensagemAviso !== ''): ?><div class="alerta alerta-info" role="status"><?= e($mensagemAviso) ?></div><?php endif; ?>

        <section class="cartao">
            <div class="perfil-topo">
                <div class="avatar"><?= e(mb_strtoupper(mb_substr($dados['nome'], 0, 1))) ?></div>
                <div>
                    <h2><?= e($dados['nome']) ?></h2>
                    <p><?= e($dados['email']) ?></p>
                    <p><?= e($dados['perfil']) ?> · cadastrado em <?= e(formatar_data($dados['data_cadastro'])) ?></p>
                </div>
            </div>
        </section>

        <?php if ($ehCliente): ?>
        <section class="cartao" id="endereco">
            <h2>Endereço de coleta</h2>
            <?php if (!$enderecoPreenchido): ?>
                <div class="alerta alerta-erro" role="alert"><strong>Falta o seu endereço.</strong> Preencha abaixo para solicitar coletas: é por ele que o nosso motorista encontra o óleo. Precisa de ajuda? <a class="link" href="ajuda.php?assunto=Cadastro">Peça para a equipe ligar</a>.</div>
            <?php else: ?>
                <p class="texto-suave">É aqui que a nossa equipe retira o óleo. Atualize sempre que mudar de endereço.</p>
            <?php endif; ?>
            <?php if ($erros['endereco'] !== ''): ?><div class="alerta alerta-erro" role="alert"><?= e($erros['endereco']) ?></div><?php endif; ?>

            <form method="post" action="perfil.php#endereco">
                <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="acao" value="endereco">
                <?php if (!$enderecoPreenchido): ?><input type="hidden" name="completar" value="1"><?php endif; ?>

                <div class="linha">
                    <div>
                        <label for="cep">CEP *</label>
                        <input type="text" id="cep" name="cep" value="<?= e($enderecoNoFormulario['cep'] !== '' ? endereco_cep_formatado($enderecoNoFormulario['cep']) : '') ?>" placeholder="00000-000" maxlength="9" inputmode="numeric" autocomplete="postal-code" data-mascara="cep" required>
                    </div>
                    <div>
                        <label for="cidade">Cidade *</label>
                        <input type="text" id="cidade" name="cidade" value="<?= e($enderecoNoFormulario['cidade']) ?>" placeholder="Ex.: Cianorte" maxlength="80" autocomplete="address-level2" required>
                    </div>
                </div>
                <div class="linha">
                    <div>
                        <label for="logradouro">Rua / Avenida *</label>
                        <input type="text" id="logradouro" name="logradouro" value="<?= e($enderecoNoFormulario['logradouro']) ?>" placeholder="Ex.: Av. Paraná" maxlength="150" autocomplete="address-line1" required>
                    </div>
                    <div>
                        <label for="numero">Número *</label>
                        <input type="text" id="numero" name="numero" value="<?= e($enderecoNoFormulario['numero']) ?>" placeholder="Ex.: 120 ou S/N" maxlength="20" required>
                    </div>
                </div>
                <div class="linha">
                    <div>
                        <label for="bairro">Bairro *</label>
                        <input type="text" id="bairro" name="bairro" value="<?= e($enderecoNoFormulario['bairro']) ?>" placeholder="Ex.: Centro" maxlength="80" autocomplete="address-level3" required>
                    </div>
                    <div>
                        <label for="uf">Estado *</label>
                        <select id="uf" name="uf" required>
                            <option value="" disabled <?= $enderecoNoFormulario['uf'] === '' ? 'selected' : '' ?>>Selecione</option>
                            <?php foreach (ENDERECO_ESTADOS as $sigla => $nomeEstado): ?>
                                <option value="<?= e($sigla) ?>" <?= $enderecoNoFormulario['uf'] === $sigla ? 'selected' : '' ?>><?= e($sigla . ' — ' . $nomeEstado) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="linha">
                    <div>
                        <label for="complemento">Complemento</label>
                        <input type="text" id="complemento" name="complemento" value="<?= e($enderecoNoFormulario['complemento']) ?>" placeholder="Bloco, sala, apto (opcional)" maxlength="80">
                    </div>
                    <div>
                        <label for="ponto_referencia">Ponto de referência</label>
                        <input type="text" id="ponto_referencia" name="ponto_referencia" value="<?= e($enderecoNoFormulario['ponto_referencia']) ?>" placeholder="Ex.: ao lado da padaria (opcional)" maxlength="150">
                    </div>
                </div>

                <button type="submit">Salvar endereço</button>
            </form>
        </section>
        <?php endif; ?>

        <section class="cartao">
            <h2>Dados do perfil</h2>
            <?php if ($erros['dados'] !== ''): ?><div class="alerta alerta-erro" role="alert"><?= e($erros['dados']) ?></div><?php endif; ?>

            <form method="post" action="perfil.php">
                <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="acao" value="dados">

                <label for="nome">Nome</label>
                <input type="text" id="nome" name="nome" maxlength="150" required
                       value="<?= e(($_POST['acao'] ?? '') === 'dados' ? (string) ($_POST['nome'] ?? '') : $dados['nome']) ?>">

                <div class="linha">
                    <div>
                        <label for="email">E-mail (usado para entrar)</label>
                        <input type="email" id="email" value="<?= e($dados['email']) ?>" disabled>
                    </div>
                    <div>
                        <label for="telefone">Telefone</label>
                        <input type="tel" id="telefone" name="telefone" maxlength="20" placeholder="(00) 00000-0000"
                               value="<?= e(($_POST['acao'] ?? '') === 'dados' ? (string) ($_POST['telefone'] ?? '') : (string) $dados['telefone']) ?>">
                    </div>
                </div>

                <button type="submit">Salvar alterações</button>
            </form>
        </section>

        <section class="cartao">
            <h2>Alterar senha</h2>
            <?php if ($erros['senha'] !== ''): ?><div class="alerta alerta-erro" role="alert"><?= e($erros['senha']) ?></div><?php endif; ?>

            <form method="post" action="perfil.php">
                <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="acao" value="senha">

                <label for="senha_atual">Senha atual</label>
                <input type="password" id="senha_atual" name="senha_atual" autocomplete="current-password" required>

                <div class="linha">
                    <div>
                        <label for="senha">Nova senha (mín. 8 caracteres)</label>
                        <input type="password" id="senha" name="senha" autocomplete="new-password" minlength="8" required>
                    </div>
                    <div>
                        <label for="confirmar">Confirmar nova senha</label>
                        <input type="password" id="confirmar" name="confirmar" autocomplete="new-password" minlength="8" required>
                    </div>
                </div>

                <button type="submit">Atualizar senha</button>
            </form>
        </section>

        <section class="cartao">
            <h2>Sessão</h2>
            <p class="texto-suave">Encerre o acesso desta conta neste navegador.</p>
            <form method="post" action="logout.php">
                <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                <button type="submit" class="botao-sair"><?= icone('sair') ?> Sair da conta</button>
            </form>
        </section>

        <?php endif; ?>
<?php layout_fechar(); ?>
