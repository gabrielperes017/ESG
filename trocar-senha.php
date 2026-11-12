<?php
declare(strict_types=1);

require __DIR__ . '/config/database.php';
require __DIR__ . '/includes/sessao.php';
require __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/senha_admin.php';

exigir_login();
exigir_perfil(['Administrador'], 'clientes');

$administrador = usuario_logado();
$erro          = '';
$conta         = null;
$erroDados     = false;

try {
    $pdo   = conectar();
    $idPostado = $_POST['id'] ?? $_GET['id'] ?? 0;
    $conta = conta_para_troca_de_senha($pdo, (int) (is_scalar($idPostado) ? $idPostado : 0));

    if ($conta !== null && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $senhaDigitada = $_POST['senha'] ?? '';

        if (!csrf_valido($_POST['csrf'] ?? null)) {
            $erro = 'A página expirou. Tente novamente.';
        } else {
            $erro = admin_trocar_senha($pdo, $administrador, $conta, is_string($senhaDigitada) ? $senhaDigitada : '') ?? '';

            if ($erro === '') {
                $_SESSION['flash'] = 'Senha de ' . $conta['nome'] . ' alterada. Passe a senha nova para a pessoa e peça que ela troque em Perfil.';
                header('Location: ' . ($conta['perfil'] === 'Cliente' ? 'clientes.php' : 'acesso.php'));
                exit;
            }
        }
    }
} catch (PDOException $excecao) {
    registrar_erro_banco('Trocar senha', $excecao);
    $erroDados = true;
}

$ehCliente  = $conta !== null && $conta['perfil'] === 'Cliente';
$paginaVolta = $ehCliente || $conta === null ? 'clientes.php' : 'acesso.php';
$souEu      = $conta !== null && (int) $conta['id'] === (int) $administrador['id'];

layout_abrir('Trocar senha', $ehCliente || $conta === null ? 'clientes' : 'acesso');
?>
        <h1>Trocar senha</h1>
        <p class="texto-suave">Defina uma senha nova para outra pessoa. Use quando ela pedir ajuda por “Esqueci minha senha”.</p>

        <?php if ($erroDados): ?>
            <div class="alerta alerta-erro" role="alert">Não foi possível carregar os dados agora. Atualize a página em instantes.</div>
            <p><a class="link" href="clientes.php">Voltar</a></p>

        <?php elseif ($conta === null): ?>
            <div class="alerta alerta-erro" role="alert">Usuário não encontrado.</div>
            <p><a class="link" href="clientes.php">Voltar para Clientes</a></p>

        <?php elseif ($souEu): ?>
            <div class="alerta alerta-erro" role="alert">Esta é a sua conta. Para trocar a sua própria senha, use a tela Perfil.</div>
            <p><a class="link" href="perfil.php">Ir para Perfil</a></p>

        <?php else: ?>
        <section class="cartao">
            <h2><?= e($conta['nome']) ?></h2>
            <p class="nota">
                E-mail: <?= e($conta['email']) ?><br>
                Telefone: <?= e($conta['telefone'] ?? '-') ?><br>
                Tipo de conta: <?= e($conta['perfil']) ?> &middot; Situação: <?= $conta['status_conta'] === 'Ativa' ? 'Ativa' : 'Inativa' ?>
            </p>

            <?php if ($conta['status_conta'] !== 'Ativa'): ?>
                <div class="alerta alerta-info" role="note">Esta conta está <strong>inativa</strong>: mesmo com a senha nova, a pessoa só consegue entrar depois que a conta for ativada.</div>
            <?php endif; ?>

            <?php if ($erro !== ''): ?><div class="alerta alerta-erro" role="alert"><?= e($erro) ?></div><?php endif; ?>

            <form method="post" action="trocar-senha.php">
                <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="id" value="<?= (int) $conta['id'] ?>">

                <label for="senha">Nova senha (mín. <?= (int) SENHA_MINIMA ?> caracteres)</label>
                <input type="text" id="senha" name="senha" value="<?= e(senha_provisoria_sugerida()) ?>"
                       minlength="<?= (int) SENHA_MINIMA ?>" maxlength="<?= (int) SENHA_MAXIMA ?>"
                       autocomplete="off" autocapitalize="off" spellcheck="false" required>
                <p class="nota">Já sugerimos uma senha pronta; você pode trocar por outra. Ela fica visível para você conseguir passá-la à pessoa. Depois de salvar, não dá para ver de novo.</p>

                <button type="submit">Trocar senha</button>
                <a class="link" href="<?= e($paginaVolta) ?>">Cancelar</a>
            </form>

            <p class="nota"><strong>Antes de passar a senha</strong>, confirme que é a própria pessoa (ligue para o telefone do cadastro). Depois peça que ela troque a senha em Perfil.</p>
        </section>
        <?php endif; ?>
<?php layout_fechar(); ?>
