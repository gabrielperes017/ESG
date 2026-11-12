<?php
declare(strict_types=1);

require __DIR__ . '/config/database.php';
require __DIR__ . '/includes/sessao.php';
require __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/senha_admin.php';

exigir_login();
exigir_perfil(['Administrador'], 'acesso');

$usuarioAtual    = usuario_logado();
$erro  = '';
$mensagemAviso    = $_SESSION['flash'] ?? '';
$novaSenha = $_SESSION['flash_senha'] ?? null;
unset($_SESSION['flash'], $_SESSION['flash_senha']);

$funcionarios = [];
$perfis       = [];
$form         = ['nome' => '', 'email' => '', 'perfil_id' => ''];
$erroDados    = false;

try {
    $pdo = conectar();

    $perfis = $pdo->query("SELECT id, nome FROM perfis_acesso WHERE nome IN ('Administrador', 'Operação', 'Visualizar') ORDER BY id")
                  ->fetchAll(PDO::FETCH_KEY_PAIR);

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $acao = (string) ($_POST['acao'] ?? '');

        if (!csrf_valido($_POST['csrf'] ?? null)) {
            $erro = 'A página expirou. Tente novamente.';

        } elseif ($acao === 'adicionar') {
            $form['nome']      = trim((string) ($_POST['nome'] ?? ''));
            $form['email']     = trim((string) ($_POST['email'] ?? ''));
            $form['perfil_id'] = (string) ($_POST['perfil_id'] ?? '');

            if ($form['nome'] === '' || mb_strlen($form['nome']) > 150) {
                $erro = 'Informe o nome (até 150 caracteres).';
            } elseif (strlen($form['email']) > 100 || !filter_var($form['email'], FILTER_VALIDATE_EMAIL)) {
                $erro = 'Informe um e-mail válido.';
            } elseif (!isset($perfis[(int) $form['perfil_id']])) {
                $erro = 'Escolha uma permissão válida.';
            } else {
                $existe = $pdo->prepare('SELECT COUNT(*) FROM usuarios WHERE email = :e');
                $existe->execute([':e' => $form['email']]);
                if ((int) $existe->fetchColumn() > 0) {
                    $erro = 'Já existe um usuário com esse e-mail.';
                } else {
                    $provisoria = senha_provisoria_sugerida();
                    $pdo->prepare(
                        'INSERT INTO usuarios (nome, email, senha, perfil_id, data_cadastro)
                         VALUES (:n, :e, :s, :p, :d)'
                    )->execute([
                        ':n' => $form['nome'], ':e' => $form['email'],
                        ':s' => password_hash($provisoria, PASSWORD_DEFAULT),
                        ':p' => (int) $form['perfil_id'], ':d' => date('Y-m-d'),
                    ]);
                    $_SESSION['flash'] = 'Funcionário adicionado.';
                    $_SESSION['flash_senha'] = ['email' => $form['email'], 'senha' => $provisoria];
                    header('Location: acesso.php');
                    exit;
                }
            }

        } elseif ($acao === 'atualizar') {
            $alvo     = (int) ($_POST['id'] ?? 0);
            $perfilId = (int) ($_POST['perfil_id'] ?? 0);
            $ativo    = isset($_POST['ativo']) ? 'Ativa' : 'Inativa';

            if ($alvo === $usuarioAtual['id']) {
                $erro = 'Você não pode alterar o seu próprio acesso por aqui (evita ficar sem administrador).';
            } elseif (!isset($perfis[$perfilId])) {
                $erro = 'Escolha uma permissão válida.';
            } else {
                $consulta = $pdo->prepare(
                    "UPDATE usuarios SET perfil_id = :p, status_conta = :s
                      WHERE id = :id AND perfil_id IN (SELECT id FROM perfis_acesso WHERE nome IN ('Administrador', 'Operação', 'Visualizar'))"
                );
                $consulta->execute([':p' => $perfilId, ':s' => $ativo, ':id' => $alvo]);
                $_SESSION['flash'] = 'Acesso atualizado.';
                header('Location: acesso.php');
                exit;
            }
        }
    }

    $funcionarios = $pdo->query(
        "SELECT u.id, u.nome, u.email, u.perfil_id, u.status_conta
           FROM usuarios u JOIN perfis_acesso p ON p.id = u.perfil_id
          WHERE p.nome IN ('Administrador', 'Operação', 'Visualizar')
          ORDER BY u.nome"
    )->fetchAll();
} catch (PDOException $excecao) {
    registrar_erro_banco('Acesso', $excecao);
    $erroDados = true;
}

layout_abrir('Restrição de acesso', 'acesso');
?>
        <h1>Restrição de acesso</h1>
        <p class="texto-suave">Defina o que cada funcionário pode fazer no sistema.</p>

        <?php if ($erroDados): ?>
            <div class="alerta alerta-erro" role="alert">Não foi possível carregar os dados agora. Atualize a página em instantes.</div>
        <?php else: ?>

        <?php if ($erro !== ''): ?><div class="alerta alerta-erro" role="alert"><?= e($erro) ?></div><?php endif; ?>
        <?php if ($mensagemAviso !== ''): ?><div class="alerta alerta-info" role="status"><?= e($mensagemAviso) ?></div><?php endif; ?>
        <?php if ($novaSenha): ?>
            <div class="alerta alerta-info" role="status">
                Senha provisória de <strong><?= e($novaSenha['email']) ?></strong>:
                <strong><?= e($novaSenha['senha']) ?></strong><br>
                Anote e repasse agora: ela não será mostrada de novo. A pessoa pode trocá-la em Perfil.
            </div>
        <?php endif; ?>

        <section class="cartao">
            <h2>Adicionar funcionário</h2>

            <form method="post" action="acesso.php">
                <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="acao" value="adicionar">

                <div class="linha">
                    <div>
                        <label for="nome">Nome</label>
                        <input type="text" id="nome" name="nome" maxlength="150" placeholder="Nome do funcionário" value="<?= e($form['nome']) ?>" required>
                    </div>
                    <div>
                        <label for="email">E-mail</label>
                        <input type="email" id="email" name="email" maxlength="100" placeholder="funcionario@email.com" value="<?= e($form['email']) ?>" required>
                    </div>
                    <div>
                        <label for="perfil_id">Permissão</label>
                        <select id="perfil_id" name="perfil_id">
                            <?php foreach ($perfis as $idDoPerfil => $nomeDoPerfil): ?>
                                <option value="<?= (int) $idDoPerfil ?>" <?= (string) $idDoPerfil === $form['perfil_id'] ? 'selected' : '' ?>><?= e($nomeDoPerfil) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <p class="nota"><strong>Administrador:</strong> tem tudo (agendar, alterar agendamentos, relatórios e esta tela).
                    <strong>Operação:</strong> agenda coletas. <strong>Visualizar:</strong> só consulta os agendamentos, sem alterar nada.</p>
                <button type="submit">Adicionar</button>
            </form>
        </section>

        <section class="cartao">
            <h2>Funcionários</h2>

            <div class="tabela-rolagem">
            <table>
                <thead>
                    <tr><th>Nome</th><th>E-mail</th><th>Permissão</th><th>Acesso ativo</th><th></th></tr>
                </thead>
                <tbody>
                <?php foreach ($funcionarios as $funcionario): $souEu = (int) $funcionario['id'] === $usuarioAtual['id']; ?>
                    <tr>
                        <td><?= e($funcionario['nome']) ?><?= $souEu ? ' <small>(você)</small>' : '' ?></td>
                        <td><?= e($funcionario['email']) ?></td>
                        <td>
                            <select name="perfil_id" form="f-<?= (int) $funcionario['id'] ?>" aria-label="Permissão de <?= e($funcionario['nome']) ?>" <?= $souEu ? 'disabled' : '' ?>>
                                <?php foreach ($perfis as $idDoPerfil => $nomeDoPerfil): ?>
                                    <option value="<?= (int) $idDoPerfil ?>" <?= (int) $idDoPerfil === (int) $funcionario['perfil_id'] ? 'selected' : '' ?>><?= e($nomeDoPerfil) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                        <td><input type="checkbox" name="ativo" value="1" form="f-<?= (int) $funcionario['id'] ?>" aria-label="Acesso ativo de <?= e($funcionario['nome']) ?>"
                                   <?= $funcionario['status_conta'] === 'Ativa' ? 'checked' : '' ?> <?= $souEu ? 'disabled' : '' ?>></td>
                        <td>
                            <?php if (!$souEu): ?>
                            <form id="f-<?= (int) $funcionario['id'] ?>" method="post" action="acesso.php">
                                <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                                <input type="hidden" name="acao" value="atualizar">
                                <input type="hidden" name="id" value="<?= (int) $funcionario['id'] ?>">
                                <button type="submit" class="botao-pequeno">Salvar</button>
                            </form>
                            <br><a class="link" href="trocar-senha.php?id=<?= (int) $funcionario['id'] ?>">Trocar senha</a>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            </div>
            <p class="nota">Desmarcar "Acesso ativo" bloqueia o login na hora. Alterações de permissão valem a partir da próxima página aberta pela pessoa.</p>
        </section>

        <?php endif; ?>
<?php layout_fechar(); ?>
