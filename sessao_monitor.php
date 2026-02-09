<?php
// Define o tempo limite (10 minutos)
$tempo_limite = 600; 

if (isset($_SESSION['logado'])) {
    // Verifica se já existe um registro de último acesso
    if (isset($_SESSION['ultimo_acesso'])) {
        $tempo_inativo = time() - $_SESSION['ultimo_acesso'];
        
        if ($tempo_inativo > $tempo_limite) {
            // Se houver a função de log (definida no seu index), registra a expulsão
            if (function_exists('registrar_log')) {
                registrar_log('Sessão encerrada por inatividade', 'O sistema deslogou o usuário automaticamente após 10 minutos.');
            }
            
            // Limpa a sessão
            session_unset();
            session_destroy();
            
            // Redireciona para o login com o aviso
            header("Location: login.php?erro=inatividade");
            exit;
        }
    }
    // Se ainda estiver ativo, atualiza o timestamp para o momento atual
    $_SESSION['ultimo_acesso'] = time();
}
?>