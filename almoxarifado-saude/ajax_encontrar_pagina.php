<?php
session_start();
include 'db.php';

if (!isset($_SESSION['logado'])) {
    header('HTTP/1.1 401 Unauthorized');
    exit('Acesso negado');
}

$id_item = $_GET['id_item'] ?? 0;

try {
    // Encontrar a posição do item na ordenação
    $stmt = $pdo->prepare("
        SELECT COUNT(*) as posicao 
        FROM itens 
        WHERE ativo = 1 AND nome <= (
            SELECT nome FROM itens WHERE id_item = ?
        )
    ");
    $stmt->execute([$id_item]);
    $posicao = $stmt->fetch()['posicao'];
    
    // Calcular página (100 itens por página)
    $pagina = ceil($posicao / 100);
    
    header('Content-Type: application/json');
    echo json_encode(['pagina' => $pagina]);
} catch(Exception $e) {
    header('HTTP/1.1 500 Internal Server Error');
    echo json_encode(['error' => $e->getMessage()]);
}
?>