<?php
session_start();
include 'db.php';
include 'sessao_monitor.php';

// VERIFICAÇÃO DE SEGURANÇA
if (!isset($_SESSION['logado']) || $_SESSION['nivel'] != 'admin') { 
    header("Location: login.php"); 
    exit; 
}

// --- FUNÇÃO registrar_log() com verificação para evitar redefinição ---
if (!function_exists('registrar_log')) {
    function registrar_log($acao, $detalhes = '') {
        global $pdo;
        if(isset($_SESSION['user_id'])) {
            try {
                $stmt = $pdo->prepare("INSERT INTO logs (id_usuario, acao, detalhes) VALUES (?, ?, ?)");
                $stmt->execute([$_SESSION['user_id'], $acao, $detalhes]);
            } catch(Exception $e) {
                // Silenciar erros de log para não quebrar a aplicação
                error_log("Erro ao registrar log: " . $e->getMessage());
            }
        }
    }
}

// LÓGICA PARA ADICIONAR UNIDADE
if(isset($_POST['add_unidade'])) {
    $nome_unidade = trim($_POST['nome_unidade']);
    
    // Verificar se já existe
    $check = $pdo->prepare("SELECT id_unidade FROM unidades WHERE LOWER(nome_unidade) = LOWER(?)");
    $check->execute([$nome_unidade]);
    
    if(!$check->fetch()) {
        $pdo->prepare("INSERT INTO unidades (nome_unidade, ativo) VALUES (?, 1)")->execute([$nome_unidade]);
        
        // Registrar log (AGORA A FUNÇÃO ESTÁ DISPONÍVEL)
        registrar_log('Nova unidade cadastrada', 'Nome: ' . $nome_unidade);
        
        header("Location: unidades.php?sucesso=1");
        exit;
    } else {
        $erro = "Unidade já cadastrada!";
    }
}

// LÓGICA PARA EDITAR UNIDADE
if(isset($_POST['editar_unidade'])) {
    $id_unidade = $_POST['id_unidade_edit'];
    $novo_nome = trim($_POST['nome_unidade_edit']);
    
    // Buscar nome antigo para log
    $stmt_antigo = $pdo->prepare("SELECT nome_unidade FROM unidades WHERE id_unidade = ?");
    $stmt_antigo->execute([$id_unidade]);
    $antigo = $stmt_antigo->fetch();
    
    $pdo->prepare("UPDATE unidades SET nome_unidade = ? WHERE id_unidade = ?")
        ->execute([$novo_nome, $id_unidade]);
    
    if($antigo) {
        registrar_log('Unidade editada', 'ID: ' . $id_unidade . ' | De: ' . $antigo['nome_unidade'] . ' | Para: ' . $novo_nome);
    }
    
    header("Location: unidades.php?sucesso=1");
    exit;
}

// LÓGICA PARA ATIVAR/DESATIVAR UNIDADE
if(isset($_GET['toggle_unidade'])) {
    $id_unidade = $_GET['toggle_unidade'];
    
    // Buscar status atual
    $stmt = $pdo->prepare("SELECT nome_unidade, ativo FROM unidades WHERE id_unidade = ?");
    $stmt->execute([$id_unidade]);
    $unidade = $stmt->fetch();
    
    if($unidade) {
        $novo_status = $unidade['ativo'] ? 0 : 1;
        $pdo->prepare("UPDATE unidades SET ativo = ? WHERE id_unidade = ?")->execute([$novo_status, $id_unidade]);
        
        $acao = $novo_status ? 'ativada' : 'desativada';
        registrar_log('Unidade ' . $acao, 'ID: ' . $id_unidade . ' | Nome: ' . $unidade['nome_unidade']);
    }
    
    header("Location: unidades.php?sucesso=1");
    exit;
}

// LÓGICA PARA EXCLUIR UNIDADE
if(isset($_GET['del_unidade'])) {
    $id_unidade = $_GET['del_unidade'];
    
    // Verificar se há usuários vinculados
    $stmt_users = $pdo->prepare("SELECT COUNT(*) as total FROM usuarios WHERE id_unidade = ?");
    $stmt_users->execute([$id_unidade]);
    $total_users = $stmt_users->fetch()['total'];
    
    if($total_users == 0) {
        // Buscar nome da unidade para log
        $stmt_nome = $pdo->prepare("SELECT nome_unidade FROM unidades WHERE id_unidade = ?");
        $stmt_nome->execute([$id_unidade]);
        $unidade_nome = $stmt_nome->fetch();
        
        $pdo->prepare("DELETE FROM unidades WHERE id_unidade = ?")->execute([$id_unidade]);
        
        if($unidade_nome) {
            registrar_log('Unidade excluída', 'ID: ' . $id_unidade . ' | Nome: ' . $unidade_nome['nome_unidade']);
        }
        
        header("Location: unidades.php?sucesso=1");
        exit;
    } else {
        $erro = "Não é possível excluir a unidade pois há usuários vinculados a ela!";
    }
}

// BUSCAR TODAS AS UNIDADES
$unidades = $pdo->query("SELECT * FROM unidades ORDER BY nome_unidade ASC")->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestão de Unidades - Almoxarifado Saúde</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body { background: #f8fafc; font-family: 'Inter', sans-serif; }
        .card { border: none; border-radius: 12px; box-shadow: 0 5px 15px rgba(0,0,0,0.02); margin-bottom: 20px; }
        .btn-voltar { background: linear-gradient(135deg, #4361ee, #3f37c9); color: white; }
    </style>
</head>
<body>

<nav class="navbar navbar-dark mb-4 p-3 shadow" style="background: linear-gradient(135deg, #4361ee, #3f37c9);">
    <div class="container d-flex justify-content-between align-items-center">
        <span class="navbar-brand fw-bold">
            <i class="fa-solid fa-hospital me-2"></i> Gestão de Unidades
        </span>
        <div class="text-white">
            <span class="me-3 small">Olá, <strong><?= htmlspecialchars($_SESSION['nome']) ?></strong></span>
            <a href="index.php" class="btn btn-outline-light btn-sm me-2">
                <i class="fa-solid fa-arrow-left me-1"></i> Voltar
            </a>
            <a href="?sair" class="btn btn-danger btn-sm">Sair</a>
        </div>
    </div>
</nav>

<div class="container">
    
    <?php if(isset($_GET['sucesso'])): ?>
        <div class="alert alert-success alert-dismissible fade show border-0 shadow-sm" role="alert">
            <i class="fa-solid fa-circle-check me-2"></i> Operação realizada com sucesso!
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>
    
    <?php if(isset($erro)): ?>
        <div class="alert alert-danger alert-dismissible fade show border-0 shadow-sm" role="alert">
            <i class="fa-solid fa-circle-exclamation me-2"></i> <?= htmlspecialchars($erro) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <div class="row">
        <div class="col-md-4">
            <div class="card p-4 shadow-sm">
                <h5 class="fw-bold text-primary mb-4">
                    <i class="fa-solid fa-plus-circle me-2"></i>Cadastrar Nova Unidade
                </h5>
                <form method="POST">
                    <div class="mb-3">
                        <label class="form-label small fw-bold">Nome da Unidade</label>
                        <input type="text" name="nome_unidade" class="form-control" placeholder="Ex: Hospital Central" required>
                    </div>
                    <button type="submit" name="add_unidade" class="btn btn-primary w-100 shadow-sm">
                        <i class="fa-solid fa-save me-2"></i>Salvar Unidade
                    </button>
                </form>
            </div>
        </div>
        
        <div class="col-md-8">
            <div class="card p-4 shadow-sm">
                <h5 class="fw-bold text-secondary mb-4">
                    <i class="fa-solid fa-list me-2"></i>Unidades Cadastradas
                </h5>
                
                <?php if(empty($unidades)): ?>
                    <div class="text-center py-5 text-muted">
                        <i class="fa-solid fa-hospital fa-3x mb-3"></i>
                        <p class="mb-0">Nenhuma unidade cadastrada ainda.</p>
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover align-middle">
                            <thead class="table-light">
                                <tr>
                                    <th>Nome da Unidade</th>
                                    <th>Status</th>
                                    <th>Usuários Vinculados</th>
                                    <th>Ações</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach($unidades as $uni): 
                                    // Contar usuários vinculados
                                    $stmt_users = $pdo->prepare("SELECT COUNT(*) as total FROM usuarios WHERE id_unidade = ?");
                                    $stmt_users->execute([$uni['id_unidade']]);
                                    $total_users = $stmt_users->fetch()['total'];
                                ?>
                                <tr>
                                    <td>
                                        <strong><?= htmlspecialchars($uni['nome_unidade']) ?></strong>
                                    </td>
                                    <td>
                                        <span class="badge bg-<?= $uni['ativo'] ? 'success' : 'danger' ?>">
                                            <?= $uni['ativo'] ? 'Ativa' : 'Inativa' ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="badge bg-info"><?= $total_users ?> usuário(s)</span>
                                    </td>
                                    <td>
                                        <button class="btn btn-sm btn-outline-primary me-1" 
                                                data-bs-toggle="modal" 
                                                data-bs-target="#editUnidade<?= $uni['id_unidade'] ?>">
                                            <i class="fa-solid fa-pen"></i>
                                        </button>
                                        
                                        <a href="?toggle_unidade=<?= $uni['id_unidade'] ?>" 
                                           class="btn btn-sm btn-outline-<?= $uni['ativo'] ? 'warning' : 'success' ?> me-1"
                                           onclick="return confirm('<?= $uni['ativo'] ? 'Desativar' : 'Ativar' ?> esta unidade?')">
                                            <i class="fa-solid fa-<?= $uni['ativo'] ? 'eye-slash' : 'eye' ?>"></i>
                                        </a>
                                        
                                        <?php if($total_users == 0): ?>
                                            <a href="?del_unidade=<?= $uni['id_unidade'] ?>" 
                                               class="btn btn-sm btn-outline-danger"
                                               onclick="return confirm('Excluir permanentemente esta unidade?')">
                                                <i class="fa-solid fa-trash"></i>
                                            </a>
                                        <?php else: ?>
                                            <span class="text-muted small" title="Não pode excluir: há usuários vinculados">
                                                <i class="fa-solid fa-lock"></i>
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                
                                <!-- Modal para editar unidade -->
                                <div class="modal fade" id="editUnidade<?= $uni['id_unidade'] ?>" tabindex="-1">
                                    <div class="modal-dialog">
                                        <div class="modal-content border-primary">
                                            <form method="POST">
                                                <div class="modal-header bg-primary text-white">
                                                    <h6 class="modal-title">Editar Unidade</h6>
                                                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                                                </div>
                                                <div class="modal-body">
                                                    <input type="hidden" name="id_unidade_edit" value="<?= $uni['id_unidade'] ?>">
                                                    <div class="mb-3">
                                                        <label class="form-label small fw-bold">Nome da Unidade</label>
                                                        <input type="text" name="nome_unidade_edit" 
                                                               value="<?= htmlspecialchars($uni['nome_unidade']) ?>" 
                                                               class="form-control" required>
                                                    </div>
                                                </div>
                                                <div class="modal-footer">
                                                    <button type="submit" name="editar_unidade" class="btn btn-primary w-100">Salvar Alterações</button>
                                                </div>
                                            </form>
                                        </div>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>