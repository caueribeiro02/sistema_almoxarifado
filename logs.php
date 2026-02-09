<?php
session_start();
include 'db.php';
include 'sessao_monitor.php';

if (!isset($_SESSION['logado']) || $_SESSION['nivel'] != 'admin') { 
    header("Location: index.php"); 
    exit; 
}

// Criar tabela de logs se não existir
$pdo->exec("
    CREATE TABLE IF NOT EXISTS logs (
        id_log INT PRIMARY KEY AUTO_INCREMENT,
        id_usuario INT,
        acao VARCHAR(255),
        detalhes TEXT,
        data_log TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (id_usuario) REFERENCES usuarios(id_usuario) ON DELETE SET NULL
    )
");

// --- ADICIONAR LÓGICA PARA LIMPAR LOGS ---
if(isset($_GET['limpar_logs'])) {
    if($_GET['limpar_logs'] == '1') {
        try {
            $pdo->exec("TRUNCATE TABLE logs");
            $msg_sucesso = "<div class='alert alert-success alert-dismissible fade show' role='alert'>
                <i class='fa-solid fa-check-circle me-2'></i>Todos os logs foram excluídos com sucesso!
                <button type='button' class='btn-close' data-bs-dismiss='alert'></button>
            </div>";
            
            // Registrar log desta ação (após limpar a tabela, recriamos a conexão)
            registrar_log('Todos os logs foram excluídos', 'Ação realizada pelo admin: ' . $_SESSION['nome']);
        } catch(Exception $e) {
            $msg_erro = "<div class='alert alert-danger alert-dismissible fade show' role='alert'>
                <i class='fa-solid fa-exclamation-triangle me-2'></i>Erro ao limpar logs: " . htmlspecialchars($e->getMessage()) . "
                <button type='button' class='btn-close' data-bs-dismiss='alert'></button>
            </div>";
        }
    }
}

// Função para registrar log (usar em todo o sistema)
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

// Filtros
$data_inicio = $_GET['data_inicio'] ?? date('Y-m-01');
$data_fim = $_GET['data_fim'] ?? date('Y-m-t');
$usuario_filtro = $_GET['usuario'] ?? 'todos';
$acao_filtro = $_GET['acao'] ?? 'todos';

// Construir query com filtros
$sql = "SELECT l.*, u.nome, u.usuario 
        FROM logs l 
        LEFT JOIN usuarios u ON l.id_usuario = u.id_usuario 
        WHERE DATE(l.data_log) BETWEEN ? AND ?";
$params = [$data_inicio, $data_fim];

if ($usuario_filtro != 'todos') {
    $sql .= " AND l.id_usuario = ?";
    $params[] = $usuario_filtro;
}

if ($acao_filtro != 'todos') {
    $sql .= " AND l.acao LIKE ?";
    $params[] = "%$acao_filtro%";
}

$sql .= " ORDER BY l.data_log DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$logs = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Buscar usuários para o filtro
$usuarios = $pdo->query("SELECT id_usuario, nome, usuario FROM usuarios ORDER BY nome ASC")->fetchAll(PDO::FETCH_ASSOC);

// Buscar ações únicas para filtro
$acoes_unicas = $pdo->query("SELECT DISTINCT acao FROM logs WHERE acao IS NOT NULL ORDER BY acao ASC")->fetchAll(PDO::FETCH_COLUMN);

// Contar total de logs
$total_logs = $pdo->query("SELECT COUNT(*) as total FROM logs")->fetch()['total'];

// Exportar para CSV
if (isset($_GET['exportar'])) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=logs_auditoria_' . date('Y-m-d') . '.csv');
    
    $output = fopen('php://output', 'w');
    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF)); // BOM UTF-8
    
    fputcsv($output, ['Data/Hora', 'Usuário', 'Login', 'Ação', 'Detalhes']);
    
    foreach ($logs as $log) {
        fputcsv($output, [
            date('d/m/Y H:i:s', strtotime($log['data_log'])),
            $log['nome'] ?? 'Usuário Excluído',
            $log['usuario'] ?? '-',
            $log['acao'],
            $log['detalhes']
        ]);
    }
    fclose($output);
    exit;
}
?>

<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Logs de Auditoria - Almoxarifado</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body { background: #f8fafc; font-family: 'Inter', sans-serif; }
        .navbar { background: linear-gradient(135deg, #2c3e50, #34495e); }
        .card { border: none; border-radius: 12px; box-shadow: 0 5px 15px rgba(0,0,0,0.05); margin-bottom: 20px; }
        .log-item { border-left: 4px solid #3498db; padding-left: 15px; margin-bottom: 10px; }
        .log-success { border-left-color: #27ae60; }
        .log-warning { border-left-color: #f39c12; }
        .log-danger { border-left-color: #e74c3c; }
        .log-info { border-left-color: #3498db; }
        .table th { background: #f8f9fa; border-top: none; }
        @media print { .no-print { display: none !important; } }
    </style>
</head>
<body>

<nav class="navbar navbar-dark mb-4 no-print p-3 shadow">
    <div class="container d-flex justify-content-between align-items-center">
        <span class="navbar-brand fw-bold">
            <i class="fa-solid fa-clipboard-list me-2"></i> Logs de Auditoria
        </span>
        <div class="text-white">
            <a href="index.php" class="btn btn-outline-light btn-sm me-2"><i class="fa-solid fa-arrow-left me-1"></i> Voltar</a>
            <a href="?sair" class="btn btn-danger btn-sm">Sair</a>
        </div>
    </div>
</nav>

<div class="container">
    
    <?php if(isset($msg_sucesso)): ?>
        <?= $msg_sucesso ?>
    <?php endif; ?>
    
    <?php if(isset($msg_erro)): ?>
        <?= $msg_erro ?>
    <?php endif; ?>
    
    <div class="row mb-4">
        <div class="col-md-12">
            <div class="card p-4">
                <h4 class="fw-bold text-dark mb-4"><i class="fa-solid fa-shield-alt me-2"></i>Registros de Auditoria do Sistema</h4>
                
                <div class="row mb-3">
                    <div class="col-md-12">
                        <div class="alert alert-info">
                            <i class="fa-solid fa-info-circle me-2"></i>
                            <strong>Total de registros:</strong> <?= $total_logs ?> logs armazenados
                        </div>
                    </div>
                </div>
                
                <form method="GET" class="row g-3">
                    <div class="col-md-3">
                        <label class="form-label small fw-bold">Data Início</label>
                        <input type="date" name="data_inicio" class="form-control form-control-sm" value="<?= htmlspecialchars($data_inicio) ?>">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label small fw-bold">Data Fim</label>
                        <input type="date" name="data_fim" class="form-control form-control-sm" value="<?= htmlspecialchars($data_fim) ?>">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label small fw-bold">Usuário</label>
                        <select name="usuario" class="form-select form-select-sm">
                            <option value="todos">Todos os Usuários</option>
                            <?php foreach($usuarios as $u): ?>
                                <option value="<?= htmlspecialchars($u['id_usuario']) ?>" <?= $usuario_filtro == $u['id_usuario'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($u['nome']) ?> (<?= htmlspecialchars($u['usuario']) ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label small fw-bold">Tipo de Ação</label>
                        <select name="acao" class="form-select form-select-sm">
                            <option value="todos">Todas as Ações</option>
                            <?php foreach($acoes_unicas as $acao): ?>
                                <option value="<?= htmlspecialchars($acao) ?>" <?= $acao_filtro == $acao ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($acao) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-12 mt-3">
                        <button type="submit" class="btn btn-primary btn-sm"><i class="fa-solid fa-filter me-1"></i> Filtrar</button>
                        <a href="logs.php" class="btn btn-secondary btn-sm ms-2"><i class="fa-solid fa-rotate me-1"></i> Limpar Filtros</a>
                        <a href="?<?= $_SERVER['QUERY_STRING'] ?>&exportar=1" class="btn btn-success btn-sm ms-2"><i class="fa-solid fa-file-excel me-1"></i> Exportar CSV</a>
                        <button onclick="window.print()" class="btn btn-dark btn-sm ms-2 no-print"><i class="fa-solid fa-print me-1"></i> Imprimir</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="card shadow-sm">
        <div class="card-header bg-white d-flex justify-content-between align-items-center">
            <h6 class="fw-bold mb-0 text-dark">
                <i class="fa-solid fa-list-check me-2"></i> Registros Encontrados: <span class="badge bg-primary"><?= count($logs) ?></span>
            </h6>
            <div class="no-print">
                <?php if($total_logs > 0): ?>
                <a href="?limpar_logs=1" class="btn btn-danger btn-sm" onclick="return confirmLimparLogs()">
                    <i class="fa-solid fa-trash-can me-1"></i> Limpar Todos os Logs
                </a>
                <?php endif; ?>
            </div>
        </div>
        
        <div class="table-responsive">
            <table class="table table-hover align-middle">
                <thead class="table-light">
                    <tr>
                        <th width="150">Data/Hora</th>
                        <th width="200">Usuário</th>
                        <th>Ação Realizada</th>
                        <th>Detalhes</th>
                        <th class="no-print" width="100">Ações</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if(empty($logs)): ?>
                        <tr>
                            <td colspan="5" class="text-center py-5 text-muted">
                                <i class="fa-solid fa-inbox fa-2x mb-3"></i><br>
                                Nenhum registro de log encontrado no período selecionado.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach($logs as $log): ?>
                            <?php
                            // Determinar classe CSS baseada no tipo de ação
                            $classe_css = 'log-info';
                            $acao = strtolower($log['acao']);
                            
                            if (strpos($acao, 'login') !== false) $classe_css = 'log-success';
                            elseif (strpos($acao, 'exclui') !== false || strpos($acao, 'cancel') !== false) $classe_css = 'log-danger';
                            elseif (strpos($acao, 'atualiz') !== false || strpos($acao, 'edit') !== false) $classe_css = 'log-warning';
                            elseif (strpos($acao, 'adicion') !== false || strpos($acao, 'inser') !== false) $classe_css = 'log-success';
                            ?>
                            
                            <tr class="<?= $classe_css ?>">
                                <td>
                                    <small class="text-muted">
                                        <i class="fa-solid fa-calendar-day me-1"></i>
                                        <?= date('d/m/Y', strtotime($log['data_log'])) ?><br>
                                        <i class="fa-solid fa-clock me-1"></i>
                                        <?= date('H:i:s', strtotime($log['data_log'])) ?>
                                    </small>
                                </td>
                                <td>
                                    <?php if($log['nome']): ?>
                                        <strong><?= htmlspecialchars($log['nome']) ?></strong><br>
                                        <small class="text-muted">@<?= htmlspecialchars($log['usuario']) ?></small>
                                    <?php else: ?>
                                        <span class="text-danger"><i class="fa-solid fa-user-slash me-1"></i>Usuário Excluído</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <strong><?= htmlspecialchars($log['acao']) ?></strong>
                                </td>
                                <td>
                                    <?php if(!empty($log['detalhes'])): ?>
                                        <small><?= nl2br(htmlspecialchars($log['detalhes'])) ?></small>
                                    <?php else: ?>
                                        <span class="text-muted">-</span>
                                    <?php endif; ?>
                                </td>
                                <td class="no-print">
                                    <button class="btn btn-sm btn-outline-info" data-bs-toggle="modal" data-bs-target="#modalDetalhes<?= $log['id_log'] ?>">
                                        <i class="fa-solid fa-eye"></i>
                                    </button>
                                </td>
                            </tr>
                            
                            <!-- Modal de detalhes -->
                            <div class="modal fade" id="modalDetalhes<?= $log['id_log'] ?>" tabindex="-1">
                                <div class="modal-dialog">
                                    <div class="modal-content">
                                        <div class="modal-header bg-dark text-white">
                                            <h6 class="modal-title">Detalhes do Log #<?= $log['id_log'] ?></h6>
                                            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                                        </div>
                                        <div class="modal-body">
                                            <div class="mb-3">
                                                <strong><i class="fa-solid fa-calendar me-1"></i> Data/Hora:</strong><br>
                                                <?= date('d/m/Y H:i:s', strtotime($log['data_log'])) ?>
                                            </div>
                                            
                                            <div class="mb-3">
                                                <strong><i class="fa-solid fa-user me-1"></i> Usuário:</strong><br>
                                                <?php if($log['nome']): ?>
                                                    <?= htmlspecialchars($log['nome']) ?> (@<?= htmlspecialchars($log['usuario']) ?>)
                                                <?php else: ?>
                                                    <span class="text-danger">Usuário Excluído</span>
                                                <?php endif; ?>
                                            </div>
                                            
                                            <div class="mb-3">
                                                <strong><i class="fa-solid fa-bolt me-1"></i> Ação:</strong><br>
                                                <?= htmlspecialchars($log['acao']) ?>
                                            </div>
                                            
                                            <?php if(!empty($log['detalhes'])): ?>
                                            <div class="mb-3">
                                                <strong><i class="fa-solid fa-file-lines me-1"></i> Detalhes:</strong><br>
                                                <div class="p-3 bg-light rounded mt-1">
                                                    <?= nl2br(htmlspecialchars($log['detalhes'])) ?>
                                                </div>
                                            </div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        
        <?php if(!empty($logs)): ?>
        <div class="card-footer bg-white border-top">
            <div class="row">
                <div class="col-md-6">
                    <small class="text-muted">
                        <i class="fa-solid fa-database me-1"></i> Total de registros: <strong><?= count($logs) ?></strong> (filtrados) | <strong><?= $total_logs ?></strong> (total)
                    </small>
                </div>
                <div class="col-md-6 text-end">
                    <small class="text-muted">
                        <i class="fa-solid fa-clock me-1"></i> Última atualização: <?= date('d/m/Y H:i:s') ?>
                    </small>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>
    
    <div class="row mt-4">
        <div class="col-md-4">
            <div class="card p-3 border-success">
                <h6 class="fw-bold text-success"><i class="fa-solid fa-circle-info me-2"></i>O que é registrado?</h6>
                <ul class="small mb-0">
                    <li>Logins e logouts</li>
                    <li>Criação/edição/exclusão de itens</li>
                    <li>Requisições e entregas</li>
                    <li>Gestão de usuários</li>
                    <li>Alterações de setores</li>
                </ul>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card p-3 border-warning">
                <h6 class="fw-bold text-warning"><i class="fa-solid fa-shield me-2"></i>Para que serve?</h6>
                <ul class="small mb-0">
                    <li>Auditoria de segurança</li>
                    <li>Rastreamento de ações</li>
                    <li>Investigação de problemas</li>
                    <li>Conformidade regulatória</li>
                    <li>Histórico de atividades</li>
                </ul>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card p-3 border-info">
                <h6 class="fw-bold text-info"><i class="fa-solid fa-triangle-exclamation me-2"></i>Importante</h6>
                <p class="small mb-0">
                    Os logs são essenciais para segurança e não devem ser desativados. 
                    Mantenha backup regular dos registros.
                </p>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
// Função de confirmação para limpar logs
function confirmLimparLogs() {
    return confirm('⚠️ ATENÇÃO: Isso excluirá TODOS os registros de log permanentemente!\n\nTotal de logs a serem excluídos: <?= $total_logs ?>\n\nDeseja continuar?');
}

// Função para limpar logs antigos (mais de 90 dias)
function limparLogsAntigos() {
    if (confirm('Deseja limpar logs com mais de 90 dias?\n\nEsta ação não pode ser desfeita.')) {
        window.location.href = '?limpar_antigos=1';
    }
}
</script>
</body>
</html>