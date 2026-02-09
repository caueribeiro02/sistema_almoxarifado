<?php
session_start();
$tempo_limite = 600; // 10 minutos

if (!isset($_SESSION['logado'])) {
    echo "expirado";
    exit;
}

if (isset($_SESSION['ultimo_acesso'])) {
    $inatividade = time() - $_SESSION['ultimo_acesso'];
    if ($inatividade > $tempo_limite) {
        session_unset();
        session_destroy();
        echo "expirado";
        exit;
    }
}

// Se o JS enviou um "ping", atualizamos o tempo de acesso
if (isset($_GET['ping'])) {
    $_SESSION['ultimo_acesso'] = time();
}

echo "ativo";