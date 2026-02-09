<?php
session_start();
include 'db.php';
include 'sessao_monitor.php';

// VERIFICAÇÃO DE SEGURANÇA
if (!isset($_SESSION['logado']) || $_SESSION['nivel'] != 'admin') { 
    header("Location: login.php"); 
    exit; 
}

// Buscar todos os itens com estoque crítico (<= 5 unidades)
$limite_critico = 5;
$itens_criticos = $pdo->query("
    SELECT * FROM itens 
    WHERE ativo = 1 AND quantidade_atual <= $limite_critico 
    ORDER BY quantidade_atual ASC, nome ASC
")->fetchAll(PDO::FETCH_ASSOC);

// Buscar itens com estoque baixo (<= 15 unidades)
$limite_baixo = 15;
$itens_baixos = $pdo->query("
    SELECT * FROM itens 
    WHERE ativo = 1 
    AND quantidade_atual > $limite_critico 
    AND quantidade_atual <= $limite_baixo 
    ORDER BY quantidade_atual ASC, nome ASC
")->fetchAll(PDO::FETCH_ASSOC);

// Buscar histórico de consumo (últimos 30 dias) para previsão
$data_limite = date('Y-m-d', strtotime('-30 days'));
$historico_consumo = [];

try {
    $stmt_consumo = $pdo->prepare("
        SELECT 
            i.id_item,
            i.nome,
            SUM(e.quantidade_entregue) as total_consumido,
            COUNT(DISTINCT DATE(e.data_entrega)) as dias_com_movimento
        FROM entregas e
        JOIN itens i ON e.id_item = i.id_item
        WHERE e.data_entrega >= ?
        GROUP BY i.id_item, i.nome
    ");
    $stmt_consumo->execute([$data_limite . " 00:00:00"]);
    $historico_consumo = $stmt_consumo->fetchAll(PDO::FETCH_ASSOC);
} catch(Exception $e) {
    // Silenciar erro se tabela não existir
}

// Calcular previsão de estoque
foreach($itens_criticos as &$item) {
    $item['consumo_diario'] = 0;
    $item['dias_restantes'] = 'N/A';
    $item['prioridade'] = 'alta';
    
    foreach($historico_consumo as $consumo) {
        if($consumo['id_item'] == $item['id_item']) {
            $dias_com_movimento = max(1, $consumo['dias_com_movimento']);
            $item['consumo_diario'] = round($consumo['total_consumido'] / $dias_com_movimento, 2);
            
            if($item['consumo_diario'] > 0) {
                $item['dias_restantes'] = floor($item['quantidade_atual'] / $item['consumo_diario']);
                
                if($item['dias_restantes'] <= 2) {
                    $item['prioridade'] = 'urgente';
                } elseif($item['dias_restantes'] <= 5) {
                    $item['prioridade'] = 'alta';
                }
            }
            break;
        }
    }
}
unset($item); // Quebrar referência

// Lógica para exportar para Excel
if(isset($_GET['exportar'])) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=estoque_critico_' . date('Y-m-d') . '.csv');
    
    $output = fopen('php://output', 'w');
    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
    
    fputcsv($output, ['Status', 'Item', 'Estoque Atual', 'Consumo Diário', 'Dias Restantes', 'Prioridade', 'Última Movimentação']);
    
    foreach($itens_criticos as $item) {
        $status = $item['quantidade_atual'] == 0 ? 'ESGOTADO' : 'CRÍTICO';
        
        fputcsv($output, [
            $status,
            $item['nome'],
            $item['quantidade_atual'],
            $item['consumo_diario'],
            $item['dias_restantes'],
            strtoupper($item['prioridade']),
            date('d/m/Y')
        ]);
    }
    
    fclose($output);
    exit;
}

// Lógica para gerar relatório PDF simplificado (imprimir página)
if(isset($_GET['gerar_pdf'])) {
    // Redireciona para versão para impressão
    header("Location: estoque_critico.php?imprimir=1");
    exit;
}
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Painel de Estoque Crítico - Almoxarifado Saúde</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --critico: #dc3545;
            --baixo: #ffc107;
            --normal: #198754;
        }
        
        body { background: #f8fafc; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
        
        .card {
            border: none;
            border-radius: 12px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.08);
            transition: transform 0.2s;
        }
        
        .card:hover {
            transform: translateY(-3px);
        }
        
        .badge-urgente {
            background: linear-gradient(135deg, #dc3545, #c82333);
            animation: pulse 1.5s infinite;
        }
        
        .badge-critico {
            background: linear-gradient(135deg, #fd7e14, #e8590c);
        }
        
        .badge-baixo {
            background: linear-gradient(135deg, #ffc107, #e6a800);
        }
        
        @keyframes pulse {
            0% { transform: scale(1); }
            50% { transform: scale(1.05); }
            100% { transform: scale(1); }
        }
        
        .progress-bar-critico {
            background: linear-gradient(90deg, #dc3545, #ff6b6b, #dc3545);
            background-size: 200% 100%;
            animation: shimmer 2s infinite linear;
        }
        
        @keyframes shimmer {
            0% { background-position: -200% 0; }
            100% { background-position: 200% 0; }
        }
        
        .table-critica tbody tr {
            transition: all 0.3s;
        }
        
        .table-critica tbody tr:hover {
            background-color: rgba(220, 53, 69, 0.05) !important;
        }
        
        .icon-status {
            width: 24px;
            height: 24px;
            border-radius: 50%;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            margin-right: 8px;
        }
        
        .icon-urgente {
            background: var(--critico);
            color: white;
            animation: blink 1s infinite;
        }
        
        @keyframes blink {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.5; }
        }
        
        .resumo-card {
            border-left: 4px solid;
            height: 100%;
        }
        
        .resumo-critico { border-left-color: var(--critico); }
        .resumo-baixo { border-left-color: var(--baixo); }
        
        @media print {
            .no-print { display: none !important; }
            .card { box-shadow: none !important; border: 1px solid #dee2e6 !important; }
            body { background: white !important; }
            .container { max-width: 100% !important; }
            .table-critica { font-size: 12px; }
            h1, h2, h3, h4, h5, h6 { color: black !important; }
        }
        
        /* ESTILO ESPECÍFICO PARA NÚMEROS EM PRETO */
        .numero-critico {
            color: #000000 !important;
            font-weight: bold;
        }
        
        .badge-numero {
            color: #000000;
            font-weight: bold;
            border: 1px solid #000000;
        }
    </style>
</head>
<body>

<nav class="navbar navbar-dark mb-4 p-3 shadow" style="background: linear-gradient(135deg, #2c3e50, #34495e);">
    <div class="container">
        <span class="navbar-brand fw-bold">
            <i class="fa-solid fa-triangle-exclamation me-2"></i> Painel de Estoque Crítico
        </span>
        <div class="text-white">
            <a href="index.php" class="btn btn-outline-light btn-sm me-2">
                <i class="fa-solid fa-arrow-left me-1"></i> Voltar
            </a>
            <button onclick="window.print()" class="btn btn-light btn-sm me-2 no-print">
                <i class="fa-solid fa-print me-1"></i> Imprimir
            </button>
        </div>
    </div>
</nav>

<div class="container mb-5">
    
    <!-- Cards de Resumo -->
    <div class="row mb-4">
        <div class="col-md-4">
            <div class="card p-3 resumo-card resumo-critico">
                <div class="d-flex align-items-center">
                    <div class="me-3">
                        <div class="icon-status icon-urgente">
                            <i class="fa-solid fa-exclamation"></i>
                        </div>
                    </div>
                    <div>
                        <h2 class="fw-bold mb-0"><?= count($itens_criticos) ?></h2>
                        <p class="text-muted mb-0">Itens Críticos (≤ 5 unidades)</p>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="col-md-4">
            <div class="card p-3 resumo-card resumo-baixo">
                <div class="d-flex align-items-center">
                    <div class="me-3">
                        <div class="icon-status" style="background: var(--baixo); color: #000;">
                            <i class="fa-solid fa-exclamation-triangle"></i>
                        </div>
                    </div>
                    <div>
                        <h2 class="fw-bold mb-0"><?= count($itens_baixos) ?></h2>
                        <p class="text-muted mb-0">Itens Baixos (≤ 15 unidades)</p>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="col-md-4">
            <div class="card p-3 bg-dark text-white">
                <div class="d-flex align-items-center">
                    <div class="me-3">
                        <i class="fa-solid fa-clock-rotate-left fa-2x"></i>
                    </div>
                    <div>
                        <h2 class="fw-bold mb-0"><?= date('d/m/Y') ?></h2>
                        <p class="mb-0 opacity-75">Última atualização</p>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Barra de Ações -->
    <div class="card p-3 mb-4 no-print">
        <div class="d-flex justify-content-between align-items-center">
            <div>
                <h5 class="fw-bold mb-0 text-dark">
                    <i class="fa-solid fa-filter me-2"></i> Filtros e Ações
                </h5>
            </div>
            <div class="btn-group">
                <button class="btn btn-outline-primary btn-sm" onclick="filtrarPorPrioridade('urgente')">
                    <i class="fa-solid fa-fire me-1"></i> Urgentes
                </button>
                <button class="btn btn-outline-warning btn-sm" onclick="filtrarPorPrioridade('alta')">
                    <i class="fa-solid fa-triangle-exclamation me-1"></i> Críticos
                </button>
                <a href="?exportar=1" class="btn btn-success btn-sm">
                    <i class="fa-solid fa-file-excel me-1"></i> Exportar Excel
                </a>
                <a href="javascript:void(0)" onclick="gerarRelatorioPDF()" class="btn btn-danger btn-sm">
                    <i class="fa-solid fa-file-pdf me-1"></i> Gerar PDF
                </a>
            </div>
        </div>
    </div>
    
    <!-- Tabela de Itens Críticos -->
    <div class="card shadow-sm">
        <div class="card-header bg-white d-flex justify-content-between align-items-center">
            <h6 class="fw-bold mb-0 text-danger">
                <i class="fa-solid fa-boxes-stacked me-2"></i> Itens com Estoque Crítico
                <span class="badge bg-danger ms-2"><?= count($itens_criticos) ?></span>
            </h6>
            <div class="no-print">
                <input type="text" id="filtroItens" class="form-control form-control-sm" 
                       placeholder="🔍 Buscar item..." style="width: 200px;">
            </div>
        </div>
        
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0 table-critica" id="tabelaCriticos">
                <thead class="table-light">
                    <tr>
                        <th width="40">#</th>
                        <th>Item</th>
                        <th width="120" class="text-center">Estoque Atual</th>
                        <th width="120" class="text-center">Consumo Diário</th>
                        <th width="120" class="text-center">Dias Restantes</th>
                        <th width="100" class="text-center">Prioridade</th>
                        <!-- REMOVIDA A COLUNA DE AÇÕES -->
                    </tr>
                </thead>
                <tbody>
                    <?php if(empty($itens_criticos)): ?>
                        <tr>
                            <td colspan="6" class="text-center py-5">
                                <i class="fa-solid fa-check-circle fa-2x text-success mb-3"></i>
                                <h5 class="text-muted">Nenhum item em estoque crítico!</h5>
                                <p class="text-muted small">Todos os itens estão com estoque acima de 5 unidades.</p>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach($itens_criticos as $index => $item): ?>
                            <?php 
                            $cor_prioridade = $item['prioridade'] == 'urgente' ? 'danger' : 'warning';
                            $icone_prioridade = $item['prioridade'] == 'urgente' ? 'fire' : 'exclamation-triangle';
                            $percentual = min(100, ($item['quantidade_atual'] / 5) * 100);
                            ?>
                            <tr data-prioridade="<?= $item['prioridade'] ?>">
                                <td class="text-muted"><?= $index + 1 ?></td>
                                <td>
                                    <div class="d-flex align-items-center">
                                        <div class="me-2">
                                            <i class="fa-solid fa-box text-secondary"></i>
                                        </div>
                                        <div>
                                            <strong><?= htmlspecialchars($item['nome']) ?></strong>
                                            <div class="progress mt-1" style="height: 4px; width: 150px;">
                                                <div class="progress-bar progress-bar-critico" 
                                                     style="width: <?= $percentual ?>%;"></div>
                                            </div>
                                        </div>
                                    </div>
                                </td>
                                <td class="text-center">
                                    <span class="badge badge-<?= $cor_prioridade ?> p-2 numero-critico" style="font-size: 1em;">
                                        <?= $item['quantidade_atual'] ?> unidades
                                    </span>
                                </td>
                                <td class="text-center">
                                    <?php if($item['consumo_diario'] > 0): ?>
                                        <span class="badge bg-dark numero-critico"><?= $item['consumo_diario'] ?>/dia</span>
                                    <?php else: ?>
                                        <span class="text-muted small">Sem dados</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-center">
                                    <?php if($item['dias_restantes'] != 'N/A'): ?>
                                        <span class="badge bg-<?= $item['dias_restantes'] <= 2 ? 'danger' : 'warning' ?> numero-critico">
                                            <?= $item['dias_restantes'] ?> dias
                                        </span>
                                    <?php else: ?>
                                        <span class="text-muted small">N/A</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-center">
                                    <span class="badge bg-<?= $cor_prioridade ?> numero-critico">
                                        <i class="fa-solid fa-<?= $icone_prioridade ?> me-1"></i>
                                        <?= strtoupper($item['prioridade']) ?>
                                    </span>
                                </td>
                                <!-- REMOVIDAS AS CÉLULAS DE AÇÕES -->
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        
        <?php if(!empty($itens_criticos)): ?>
        <div class="card-footer bg-white border-top">
            <div class="row">
                <div class="col-md-6">
                    <small class="text-muted">
                        <i class="fa-solid fa-circle-info me-1"></i>
                        Exibindo <?= count($itens_criticos) ?> itens críticos
                    </small>
                </div>
                <div class="col-md-6 text-end">
                    <button class="btn btn-sm btn-danger no-print" onclick="imprimirRelatorioCritico()">
                        <i class="fa-solid fa-file-pdf me-1"></i> Imprimir Relatório
                    </button>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>
    
    <!-- Seção de Itens com Estoque Baixo (opcional) -->
    <?php if(!empty($itens_baixos)): ?>
    <div class="card shadow-sm mt-4">
        <div class="card-header bg-white">
            <h6 class="fw-bold mb-0 text-warning">
                <i class="fa-solid fa-exclamation-triangle me-2"></i> Itens com Estoque Baixo (Atenção)
                <span class="badge bg-warning ms-2"><?= count($itens_baixos) ?></span>
            </h6>
        </div>
        <div class="table-responsive">
            <table class="table table-sm align-middle">
                <thead>
                    <tr>
                        <th>Item</th>
                        <th class="text-center">Estoque</th>
                        <th class="text-center">Status</th>
                        <!-- REMOVIDA A COLUNA DE AÇÃO SUGERIDA -->
                    </tr>
                </thead>
                <tbody>
                    <?php foreach($itens_baixos as $item): ?>
                    <tr>
                        <td><?= htmlspecialchars($item['nome']) ?></td>
                        <td class="text-center">
                            <span class="badge bg-warning numero-critico"><?= $item['quantidade_atual'] ?> un</span>
                        </td>
                        <td class="text-center">
                            <span class="badge bg-light text-dark border">Monitorar</span>
                        </td>
                        <!-- REMOVIDA A CÉLULA DE AÇÃO SUGERIDA -->
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
// Filtro de busca na tabela
document.getElementById('filtroItens').addEventListener('input', function() {
    const filter = this.value.toUpperCase();
    const rows = document.querySelectorAll('#tabelaCriticos tbody tr');
    
    rows.forEach(row => {
        const text = row.innerText.toUpperCase();
        row.style.display = text.includes(filter) ? '' : 'none';
    });
});

// Filtrar por prioridade
function filtrarPorPrioridade(prioridade) {
    const rows = document.querySelectorAll('#tabelaCriticos tbody tr[data-prioridade]');
    const todasBtn = document.querySelector('.btn-todas');
    
    rows.forEach(row => {
        if(prioridade === 'todas') {
            row.style.display = '';
        } else {
            row.style.display = row.getAttribute('data-prioridade') === prioridade ? '' : 'none';
        }
    });
}

// Gerar relatório PDF simplificado (imprimir página)
function gerarRelatorioPDF() {
    window.print();
}

// Imprimir relatório
function imprimirRelatorioCritico() {
    // Abre nova janela com conteúdo formatado para impressão
    const conteudo = `
        <html>
        <head>
            <title>Relatório de Estoque Crítico - ${new Date().toLocaleDateString('pt-BR')}</title>
            <style>
                body { font-family: Arial, sans-serif; margin: 20px; }
                h1 { color: #dc3545; text-align: center; }
                h2 { color: #ffc107; }
                .header { text-align: center; margin-bottom: 30px; }
                .header p { margin: 5px 0; }
                table { width: 100%; border-collapse: collapse; margin-top: 20px; }
                th { background: #f8f9fa; color: #000; font-weight: bold; }
                td, th { border: 1px solid #000; padding: 8px; text-align: left; }
                .urgente { background: #fff5f5; }
                .critico { background: #fff9db; }
                .baixo { background: #fff9db; }
                .footer { margin-top: 30px; text-align: center; font-size: 12px; color: #666; }
                .page-break { page-break-before: always; }
            </style>
        </head>
        <body>
            <div class="header">
                <h1>Relatório de Estoque Crítico</h1>
                <p><strong>Data:</strong> ${new Date().toLocaleDateString('pt-BR')} ${new Date().toLocaleTimeString('pt-BR')}</p>
                <p><strong>Unidade:</strong> Sistema Almoxarifado Saúde</p>
            </div>
            
            <h2>Itens Críticos (Estoque ≤ 5 unidades)</h2>
            <p><strong>Total:</strong> <?= count($itens_criticos) ?> itens</p>
            
            <table>
                <thead>
                    <tr>
                        <th>Item</th>
                        <th>Estoque Atual</th>
                        <th>Prioridade</th>
                        <th>Dias Restantes</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach($itens_criticos as $item): ?>
                    <tr class="<?= $item['prioridade'] ?>">
                        <td><?= htmlspecialchars($item['nome']) ?></td>
                        <td><strong><?= $item['quantidade_atual'] ?> unidades</strong></td>
                        <td><?= strtoupper($item['prioridade']) ?></td>
                        <td><?= $item['dias_restantes'] ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            
            <?php if(!empty($itens_baixos)): ?>
            <div class="page-break"></div>
            <h2>Itens com Estoque Baixo (Estoque ≤ 15 unidades)</h2>
            <p><strong>Total:</strong> <?= count($itens_baixos) ?> itens</p>
            
            <table>
                <thead>
                    <tr>
                        <th>Item</th>
                        <th>Estoque Atual</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach($itens_baixos as $item): ?>
                    <tr class="baixo">
                        <td><?= htmlspecialchars($item['nome']) ?></td>
                        <td><?= $item['quantidade_atual'] ?> unidades</td>
                        <td>Monitorar</td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
            
            <div class="footer">
                <p>Relatório gerado automaticamente pelo Sistema de Almoxarifado Saúde</p>
                <p>${new Date().toLocaleDateString('pt-BR')} ${new Date().toLocaleTimeString('pt-BR')}</p>
            </div>
        </body>
        </html>
    `;
    
    const janela = window.open('', '_blank');
    janela.document.write(conteudo);
    janela.document.close();
    janela.print();
}

// Atualizar automaticamente a cada 5 minutos (opcional)
setTimeout(() => {
    window.location.reload();
}, 5 * 60 * 1000); // 5 minutos
</script>
</body>
</html>