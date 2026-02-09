<?php
session_start();
include 'db.php';

if (!isset($_SESSION['logado'])) {
    header('HTTP/1.1 401 Unauthorized');
    exit('Acesso negado');
}

try {
    // Buscar todos os itens ativos
    $stmt = $pdo->prepare("SELECT id_item, nome, quantidade_atual FROM itens WHERE ativo = 1 ORDER BY nome ASC");
    $stmt->execute();
    $itens = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    header('Content-Type: application/json');
    echo json_encode($itens);
} catch(Exception $e) {
    header('HTTP/1.1 500 Internal Server Error');
    echo json_encode(['error' => $e->getMessage()]);
}
?>