<?php
include 'db.php';
$msg = "";
$pergunta_exibir = "";
$usuario_encontrado = "";

// PASSO 1: Buscar a pergunta do usuário quando ele digita o login
if (isset($_POST['buscar_usuario'])) {
    $user = trim($_POST['usuario']);
    $stmt = $pdo->prepare("SELECT pergunta_seguranca FROM usuarios WHERE usuario = ?");
    $stmt->execute([$user]);
    $dados = $stmt->fetch();

    if ($dados) {
        $pergunta_exibir = $dados['pergunta_seguranca'];
        $usuario_encontrado = $user;
    } else {
        $msg = "<div class='alert alert-danger py-2 small'>Usuário não encontrado em nossa base!</div>";
    }
}

// PASSO 2: Validar a resposta e a nova senha forte
if (isset($_POST['recuperar'])) {
    $user = $_POST['usuario_final'];
    $resp = trim($_POST['resposta']);
    $nova_senha = $_POST['nova_senha'];

    // Validação de Senha Forte no Servidor
    if (!preg_match('/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[@$!%*?&])[A-Za-z\d@$!%*?&]{8,}$/', $nova_senha)) {
        $msg = "<div class='alert alert-danger py-2 small'>A senha não cumpre os requisitos mínimos!</div>";
    } else {
        $stmt = $pdo->prepare("SELECT id_usuario FROM usuarios WHERE usuario = ? AND resposta_seguranca = ?");
        $stmt->execute([$user, $resp]);
        
        if ($stmt->fetch()) {
            $hash = password_hash($nova_senha, PASSWORD_DEFAULT);
            $pdo->prepare("UPDATE usuarios SET senha = ? WHERE usuario = ?")->execute([$hash, $user]);
            $msg = "<div class='alert alert-success py-2 small text-center'>✅ Senha atualizada com sucesso! <br> <a href='login.php' class='fw-bold text-decoration-none'>Clique aqui para fazer Login</a></div>";
        } else {
            $msg = "<div class='alert alert-danger py-2 small'>❌ Resposta de segurança incorreta!</div>";
            // Repete a busca da pergunta para não sumir da tela
            $usuario_encontrado = $user;
            $stmt_p = $pdo->prepare("SELECT pergunta_seguranca FROM usuarios WHERE usuario = ?");
            $stmt_p->execute([$user]);
            $pergunta_exibir = $stmt_p->fetch()['pergunta_seguranca'];
        }
    }
}
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Recuperar Senha - Almoxarifado</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body { background: #f0f2f5; height: 100vh; display: flex; align-items: center; justify-content: center; font-family: 'Segoe UI', sans-serif; }
        .card-recuperar { background: white; padding: 35px; border-radius: 20px; box-shadow: 0 10px 30px rgba(0,0,0,0.1); width: 100%; max-width: 420px; }
        .btn-primary { background: #4361ee; border: none; padding: 10px; border-radius: 8px; font-weight: 600; }
    </style>
</head>
<body>
    <div class="card-recuperar shadow">
        <h4 class="text-center fw-bold mb-4 text-primary"><i class="fa-solid fa-user-shield me-2"></i>Recuperar Acesso</h4>
        <?= $msg ?>

        <?php if ($usuario_encontrado == ""): ?>
            <form method="POST">
                <div class="mb-3 text-start">
                    <label class="form-label small fw-bold">Informe seu Login</label>
                    <input type="text" name="usuario" class="form-control" placeholder="Ex: caue" required autofocus>
                </div>
                <button type="submit" name="buscar_usuario" class="btn btn-primary w-100 fw-bold">Verificar Usuário</button>
            </form>
        <?php else: ?>
            <form method="POST">
                <input type="hidden" name="usuario_final" value="<?= $usuario_encontrado ?>">
                
                <div class="mb-3 text-start">
                    <label class="form-label small fw-bold text-primary">Sua Pergunta de Segurança:</label>
                    <div class="p-3 bg-light rounded border mb-2 text-dark">
                        <i class="fa-solid fa-comment-dots me-2 text-primary"></i><strong><?= htmlspecialchars($pergunta_exibir) ?></strong>
                    </div>
                    <label class="form-label small fw-bold">Sua Resposta:</label>
                    <input type="text" name="resposta" class="form-control" placeholder="Digite a resposta secreta" required autofocus>
                </div>

                <div class="mb-4 text-start">
                    <label class="form-label small fw-bold">Nova Senha Forte</label>
                    <input type="password" name="nova_senha" id="n_pass" class="form-control" placeholder="8+ caracteres, símbolos..." required>
                    <div id="n_msg" class="mt-1" style="min-height: 20px;"></div>
                </div>

                <button type="submit" name="recuperar" id="n_btn" class="btn btn-primary w-100 fw-bold shadow-sm" disabled>Redefinir Minha Senha</button>
            <?php endif; ?>
            
            <div class="text-center mt-4">
                <a href="login.php" class="text-decoration-none small text-muted"><i class="fa-solid fa-arrow-left me-1"></i> Voltar ao Login</a>
            </div>
        </form>
    </div>

    <script>
    // Validação de senha forte via JavaScript
    const pass = document.getElementById('n_pass');
    const msg = document.getElementById('n_msg');
    const btn = document.getElementById('n_btn');

    if(pass) {
        pass.addEventListener('input', () => {
            const regex = /^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[@$!%*?&])[A-Za-z\d@$!%*?&]{8,}$/;
            if (regex.test(pass.value)) {
                msg.innerHTML = '<small class="text-success fw-bold"><i class="fa-solid fa-check"></i> Senha Segura!</small>';
                btn.disabled = false;
            } else {
                msg.innerHTML = '<small class="text-danger" style="font-size: 0.7rem;"><i class="fa-solid fa-xmark"></i> Use: 8 letras, 1 Maiúscula, 1 Número e 1 Símbolo.</small>';
                btn.disabled = true;
            }
        });
    }
    </script>
</body>
</html>