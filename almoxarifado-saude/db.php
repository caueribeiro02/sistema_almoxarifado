<?php
// db.php - VERSÃO SEGURA
$host = "localhost";
$user = "root";  
$pass = ""; 
$db = "almoxarifado";
$port = "3306";

// CONFIGURAÇÕES DE SEGURANÇA IMPORTANTES
$options = [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false, // CRÍTICO PARA SEGURANÇA
    PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4"
];

try {
    $pdo = new PDO("mysql:host=$host;port=$port;dbname=$db;charset=utf8mb4", $user, $pass, $options);
} catch (PDOException $e) {
    // EM PRODUÇÃO: Não mostrar erro detalhado
    error_log("ERRO DB: " . $e->getMessage());
    
    // Mensagem amigável para o usuário
    if (strpos($_SERVER['HTTP_HOST'], 'localhost') !== false) {
        // Se estiver no localhost, mostra erro detalhado
        die("Erro de conexão: " . $e->getMessage());
    } else {
        // Se estiver em produção, mostra mensagem genérica
        die("Erro de conexão com o banco de dados. Contate o administrador.");
    }
}
?>