<?php
session_start();
include 'db.php';

if (!isset($_SESSION['logado'])) {
    header('HTTP/1.1 401 Unauthorized');
    exit('Acesso negado');
}

$termo = $_GET['termo'] ?? '';

try {
    $sql = "SELECT id_item, nome, quantidade_atual FROM itens WHERE ativo = 1";
    
    if (!empty($termo)) {
        $sql .= " AND (nome LIKE :termo OR nome LIKE :termo2)";
        $termoBusca = "%$termo%";
        $termoBusca2 = "%" . str_replace(' ', '%', $termo) . "%";
    }
    
    $sql .= " ORDER BY nome ASC LIMIT 100";
    
    $stmt = $pdo->prepare($sql);
    
    if (!empty($termo)) {
        $stmt->bindParam(':termo', $termoBusca);
        $stmt->bindParam(':termo2', $termoBusca2);
    }
    
    $stmt->execute();
    $itens = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    header('Content-Type: application/json');
    echo json_encode($itens);
} catch(Exception $e) {
    header('HTTP/1.1 500 Internal Server Error');
    echo json_encode(['error' => $e->getMessage()]);
}
?>