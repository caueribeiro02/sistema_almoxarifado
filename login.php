<?php
session_start();
include 'db.php';

if (isset($_SESSION['logado'])) {
    header("Location: index.php");
    exit;
}

// ============================================
// PROTEÇÃO CONTRA BRUTE FORCE (APENAS POR USUÁRIO)
// ============================================
$max_tentativas_usuario = 5;  // Máximo de tentativas por usuário
$tempo_bloqueio = 5;          // Minutos de bloqueio

// Obter IP apenas para registro (não para bloqueio)
$ip = $_SERVER['REMOTE_ADDR'];

// ============================================
// PROCESSAMENTO DO LOGIN
// ============================================

if (isset($_POST['logar'])) {
    $user = trim($_POST['usuario']);
    $pass = trim($_POST['senha']);
    
    // Verificar tentativas do usuário
    $stmt = $pdo->prepare("SELECT COUNT(*) as total FROM tentativas_login WHERE usuario = ? AND data_tentativa > DATE_SUB(NOW(), INTERVAL $tempo_bloqueio MINUTE)");
    $stmt->execute([$user]);
    $tentativas_usuario = $stmt->fetch()['total'];
    $usuario_bloqueado = ($tentativas_usuario >= $max_tentativas_usuario);
    
    if ($usuario_bloqueado) {
        $erro = "Muitas tentativas para este usuário. Tente novamente em $tempo_bloqueio minutos.";
    } else {
        // Verificar usuário e senha
        $stmt = $pdo->prepare("SELECT * FROM usuarios WHERE usuario = ?");
        $stmt->execute([$user]);
        $dados = $stmt->fetch();

        if ($dados && password_verify($pass, $dados['senha'])) {
            // LOGIN BEM-SUCEDIDO
            
            // Limpar tentativas deste usuário após login bem-sucedido
            $pdo->prepare("DELETE FROM tentativas_login WHERE usuario = ?")->execute([$user]);
            
            // Configurar sessão
            $_SESSION['logado'] = true;
            $_SESSION['user_id'] = $dados['id_usuario'];
            $_SESSION['nome'] = $dados['nome'];
            $_SESSION['nivel'] = $dados['nivel'];
            $_SESSION['ultimo_acesso'] = time(); 
            
            // Registrar log de login
            try {
                $stmt_log = $pdo->prepare("INSERT INTO logs (id_usuario, acao, detalhes) VALUES (?, ?, ?)");
                $stmt_log->execute([$dados['id_usuario'], 'Login no sistema', 'IP: ' . $ip]);
            } catch(Exception $e) {
                // Ignorar erro se tabela não existir
            }
            
            header("Location: index.php");
            exit;
        } else {
            // LOGIN FALHOU
            
            // Registrar tentativa falha
            $stmt = $pdo->prepare("INSERT INTO tentativas_login (usuario, ip, data_tentativa) VALUES (?, ?, NOW())");
            $stmt->execute([$user, $ip]);
            
            // Mensagem de erro
            $erro = "Usuário ou senha incorretos!";
            
            // Adicionar contador de tentativas restantes
            $tentativas_restantes = $max_tentativas_usuario - ($tentativas_usuario + 1);
            if ($tentativas_restantes > 0) {
                $erro .= " Tentativas restantes: $tentativas_restantes";
            } else {
                $erro .= " Conta bloqueada por $tempo_bloqueio minutos.";
            }
        }
    }
    
    // Limpar tentativas antigas periodicamente (1 em cada 10 logins)
    if (rand(1, 10) == 1) {
        $pdo->exec("DELETE FROM tentativas_login WHERE data_tentativa < DATE_SUB(NOW(), INTERVAL $tempo_bloqueio MINUTE)");
    }
}
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - Almoxarifado Saúde</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root { --primary-color: #4361ee; --secondary-color: #3f37c9; }
        body { margin: 0; padding: 0; font-family: 'Inter', sans-serif; height: 100vh; display: flex; align-items: center; justify-content: center; overflow: hidden; background: #0f172a; }
        
        /* Fundo com movimento */
        .background-blobs { position: absolute; width: 100%; height: 100%; z-index: -1; filter: blur(80px); }
        .blob { position: absolute; background: var(--primary-color); border-radius: 50%; opacity: 0.4; animation: move 20s infinite alternate; }
        .blob-1 { width: 400px; height: 400px; top: -100px; left: -100px; background: #4cc9f0; }
        .blob-2 { width: 500px; height: 500px; bottom: -150px; right: -100px; background: #7209b7; animation-delay: -5s; }
        .blob-3 { width: 300px; height: 300px; top: 40%; left: 60%; background: #4361ee; animation-delay: -10s; }
        @keyframes move { from { transform: translate(0, 0) scale(1); } to { transform: translate(100px, 100px) scale(1.2); } }

        /* Cartão de Vidro */
        .login-card { 
            background: rgba(255, 255, 255, 0.1); 
            backdrop-filter: blur(15px); 
            -webkit-backdrop-filter: blur(15px); 
            border: 1px solid rgba(255, 255, 255, 0.1); 
            padding: 40px; 
            border-radius: 24px; 
            width: 100%; 
            max-width: 400px; 
            color: white; 
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.5); 
            animation: fadeIn 0.8s ease-out; 
        }
        @keyframes fadeIn { from { opacity: 0; transform: translateY(20px); } to { opacity: 1; transform: translateY(0); } }

        /* Estilo do Input e do Olho */
        .password-wrapper { position: relative; display: flex; align-items: center; }
        .form-control { 
            background: rgba(255, 255, 255, 0.05); 
            border: 1px solid rgba(255, 255, 255, 0.2); 
            color: white !important; 
            padding: 12px 15px; 
            border-radius: 12px; 
            width: 100%;
        }
        .form-control:focus { background: rgba(255, 255, 255, 0.1); border-color: var(--primary-color); box-shadow: none; }
        .form-control::placeholder { color: rgba(255, 255, 255, 0.4); }

        .toggle-password { 
            position: absolute; 
            right: 15px; 
            cursor: pointer; 
            color: rgba(255, 255, 255, 0.5); 
            z-index: 10;
            transition: 0.3s;
        }
        .toggle-password:hover { color: white; }

        .btn-primary { background: var(--primary-color); border: none; padding: 12px; border-radius: 12px; font-weight: 600; transition: 0.3s; }
        .btn-primary:hover { background: var(--secondary-color); transform: translateY(-2px); box-shadow: 0 10px 20px -5px rgba(67, 97, 238, 0.4); }
        .footer-link { color: rgba(255, 255, 255, 0.6); text-decoration: none; font-size: 0.85rem; transition: 0.3s; }
        .footer-link:hover { color: white; }
    </style>
</head>
<body>

    <div class="background-blobs">
        <div class="blob blob-1"></div>
        <div class="blob blob-2"></div>
        <div class="blob blob-3"></div>
    </div>

    <div class="login-card">
        <div class="text-center mb-4">
            <div style="font-size: 2.5rem; background: linear-gradient(135deg, #4cc9f0, #4361ee); -webkit-background-clip: text; -webkit-text-fill-color: transparent;">
                <i class="fa-solid fa-hospital-user"></i>
            </div>
            <h4 class="fw-bold mt-2">Almoxarifado Saúde</h4>
            <p class="small" style="color: rgba(255,255,255,0.5)">Acesso restrito a funcionários</p>
        </div>

        <?php if(isset($_GET['erro']) && $_GET['erro'] == 'inatividade'): ?>
            <div class="alert py-2 small border-0 text-center mb-4" style="background: rgba(255, 193, 7, 0.2); color: #ffc107;">
                <i class="fa-solid fa-clock-rotate-left me-2"></i>Sessão encerrada por inatividade.
            </div>
        <?php endif; ?>

        <?php if(isset($erro)): ?>
            <div class="alert py-2 small border-0 text-center mb-4" style="background: rgba(220, 53, 69, 0.2); color: #ff8585;">
                <i class="fa-solid fa-circle-exclamation me-2"></i><?= $erro ?>
            </div>
        <?php endif; ?>

        <form method="POST">
            <div class="mb-3 text-start">
                <label class="form-label small fw-bold">Usuário</label>
                <input type="text" name="usuario" class="form-control" placeholder="Seu login" required autofocus>
            </div>
            
            <div class="mb-4 text-start">
                <label class="form-label small fw-bold">Senha</label>
                <div class="password-wrapper">
                    <input type="password" name="senha" id="field-pass" class="form-control" placeholder="••••••••" required>
                    <i class="fa-solid fa-eye toggle-password" id="eye-icon"></i>
                </div>
            </div>

            <button type="submit" name="logar" class="btn btn-primary w-100 shadow">ENTRAR NO PAINEL</button>
            
            <div class="text-center mt-4 pt-3 border-top" style="border-color: rgba(255,255,255,0.1) !important;">
                <a href="recuperar.php" class="footer-link">Esqueceu sua senha?</a>
            </div>
        </form>
    </div>

    <script>
        const passField = document.getElementById('field-pass');
        const eyeIcon = document.getElementById('eye-icon');

        eyeIcon.addEventListener('click', () => {
            const type = passField.getAttribute('type') === 'password' ? 'text' : 'password';
            passField.setAttribute('type', type);
            
            // Troca o ícone
            eyeIcon.classList.toggle('fa-eye');
            eyeIcon.classList.toggle('fa-eye-slash');
        });
    </script>
</body>
</html>