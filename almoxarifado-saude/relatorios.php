<?php
session_start();
include 'db.php';
include 'sessao_monitor.php';

if (!isset($_SESSION['logado'])) { header("Location: login.php"); exit; }

// Inicializa variáveis de filtro
$data_inicio = $_GET['data_inicio'] ?? date('Y-m-01');
$data_fim = $_GET['data_fim'] ?? date('Y-m-t');      
$id_setor = $_GET['id_setor'] ?? 'todos';
$id_unidade = $_GET['id_unidade'] ?? 'todos'; // Novo filtro de unidade

// 1. Construção da Query SQL (Atualizada com JOIN para Unidades)
$sql = "SELECT e.*, i.nome as nome_item, s.nome_setor, un.nome_unidade 
        FROM entregas e 
        JOIN itens i ON e.id_item = i.id_item 
        JOIN setores s ON e.id_setor = s.id_setor
        LEFT JOIN unidades un ON e.id_unidade_destino = un.id_unidade 
        WHERE e.data_entrega BETWEEN ? AND ?";

$params = [$data_inicio . " 00:00:00", $data_fim . " 23:59:59"];

if ($id_setor != 'todos') { 
    $sql .= " AND e.id_setor = ?"; 
    $params[] = $id_setor; 
}

if ($id_unidade != 'todos') { 
    $sql .= " AND e.id_unidade_destino = ?"; 
    $params[] = $id_unidade; 
}

$sql .= " ORDER BY e.data_entrega DESC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$resultados = $stmt->fetchAll(PDO::FETCH_ASSOC);

// 2. Lógica para Exportação Excel (CSV)
if (isset($_GET['exportar'])) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=relatorio_almoxarifado.csv');
    $output = fopen('php://output', 'w');
    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF)); 
    fputcsv($output, ['Data', 'Unidade', 'Material', 'Quantidade', 'Setor', 'Responsável']);
    foreach ($resultados as $linha) {
        fputcsv($output, [
            date('d/m/Y H:i', strtotime($linha['data_entrega'])), 
            $linha['nome_unidade'] ?? 'N/A',
            $linha['nome_item'], 
            $linha['quantidade_entregue'], 
            $linha['nome_setor'], 
            $linha['funcionario']
        ]);
    }
    fclose($output);
    exit;
}

// 3. Preparação de dados para os Gráficos
$dados_grafico_itens = [];
$dados_grafico_setores = [];
$dados_grafico_unidades = []; // Novo array para unidades

foreach ($resultados as $r) {
    $item = $r['nome_item'];
    $setor = $r['nome_setor'];
    $unidade = $r['nome_unidade'] ?? 'Sem Unidade';
    $qtd = (int)$r['quantidade_entregue'];

    @$dados_grafico_itens[$item] += $qtd;
    @$dados_grafico_setores[$setor] += $qtd;
    @$dados_grafico_unidades[$unidade] += $qtd;
}

$setores_lista = $pdo->query("SELECT * FROM setores ORDER BY nome_setor ASC")->fetchAll(PDO::FETCH_ASSOC);
$unidades_lista = $pdo->query("SELECT * FROM unidades ORDER BY nome_unidade ASC")->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <title>Dashboard Almoxarifado</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script> 
    <style>
        body { background: #f4f7f6; font-family: 'Inter', sans-serif; }
        .card { border: none; border-radius: 12px; box-shadow: 0 4px 10px rgba(0,0,0,0.05); }
        .chart-container { position: relative; height: 300px; width: 100%; }
        @media print { .no-print { display: none !important; } }
    </style>
</head>
<body>

<div class="container mt-4 mb-5">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h2 class="fw-bold text-primary"><i class="fa-solid fa-chart-line me-2"></i>Relatórios e Dashboard</h2>
        <div class="no-print d-flex gap-2">
            <a href="index.php" class="btn btn-outline-secondary"><i class="fa-solid fa-arrow-left"></i> Painel</a>
            <a href="?<?= $_SERVER['QUERY_STRING'] ?>&exportar=1" class="btn btn-success"><i class="fa-solid fa-file-excel"></i> Exportar Excel</a>
            <button onclick="window.print()" class="btn btn-primary"><i class="fa-solid fa-print"></i> Imprimir</button>
        </div>
    </div>

    <div class="card p-3 mb-4 no-print">
        <form method="GET" class="row g-2 align-items-end">
            <div class="col-md-2"><label class="small fw-bold">Início</label><input type="date" name="data_inicio" class="form-control form-control-sm" value="<?= $data_inicio ?>"></div>
            <div class="col-md-2"><label class="small fw-bold">Fim</label><input type="date" name="data_fim" class="form-control form-control-sm" value="<?= $data_fim ?>"></div>
            <div class="col-md-3">
                <label class="small fw-bold">Unidade</label>
                <select name="id_unidade" class="form-select form-select-sm">
                    <option value="todos">Todas as Unidades</option>
                    <?php foreach($unidades_lista as $u): ?>
                        <option value="<?= $u['id_unidade'] ?>" <?= $id_unidade == $u['id_unidade'] ? 'selected' : '' ?>><?= htmlspecialchars($u['nome_unidade']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <label class="small fw-bold">Setor</label>
                <select name="id_setor" class="form-select form-select-sm">
                    <option value="todos">Todos os Setores</option>
                    <?php foreach($setores_lista as $s): ?>
                        <option value="<?= $s['id_setor'] ?>" <?= $id_setor == $s['id_setor'] ? 'selected' : '' ?>><?= htmlspecialchars($s['nome_setor']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2"><button type="submit" class="btn btn-dark btn-sm w-100">Filtrar</button></div>
        </form>
    </div>

    <div class="row mb-4">
        <div class="col-md-12">
            <div class="card p-3 shadow-sm">
                <h6 class="fw-bold border-bottom pb-2 text-info"><i class="fa-solid fa-hospital me-2"></i>Consumo Total por Unidade</h6>
                <div class="chart-container">
                    <canvas id="graficoUnidades"></canvas>
                </div>
            </div>
        </div>
    </div>

    <div class="row mb-4">
        <div class="col-md-7">
            <div class="card p-3 h-100">
                <h6 class="fw-bold border-bottom pb-2">Consumo por Material</h6>
                <canvas id="graficoItens"></canvas>
            </div>
        </div>
        <div class="col-md-5">
            <div class="card p-3 h-100">
                <h6 class="fw-bold border-bottom pb-2">Distribuição por Setor</h6>
                <canvas id="graficoSetores"></canvas>
            </div>
        </div>
    </div>

    <div class="card border-0 shadow-sm">
        <div class="table-responsive">
            <table class="table table-striped align-middle mb-0">
                <thead class="table-light">
                    <tr><th>Data/Hora</th><th>Unidade</th><th>Material</th><th class="text-center">Qtd</th><th>Setor</th><th>Pessoa</th></tr>
                </thead>
                <tbody>
                    <?php foreach ($resultados as $r): ?>
                    <tr>
                        <td><small><?= date('d/m/Y H:i', strtotime($r['data_entrega'])) ?></small></td>
                        <td><span class="badge bg-info text-dark"><?= htmlspecialchars($r['nome_unidade'] ?? 'N/A') ?></span></td>
                        <td class="fw-bold"><?= htmlspecialchars($r['nome_item']) ?></td>
                        <td class="text-center"><span class="badge bg-secondary"><?= $r['quantidade_entregue'] ?></span></td>
                        <td><?= htmlspecialchars($r['nome_setor']) ?></td>
                        <td><small><?= htmlspecialchars($r['funcionario']) ?></small></td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if(empty($resultados)): ?>
                        <tr><td colspan="6" class="text-center py-4 text-muted">Nenhum registro encontrado para este período.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script>
// 1. Gráfico de Unidades (Barras Horizontais)
new Chart(document.getElementById('graficoUnidades'), {
    type: 'bar',
    data: {
        labels: <?= json_encode(array_keys($dados_grafico_unidades)) ?>,
        datasets: [{
            label: 'Total de Itens Entregues',
            data: <?= json_encode(array_values($dados_grafico_unidades)) ?>,
            backgroundColor: '#4cc9f0',
            borderColor: '#4361ee',
            borderWidth: 1
        }]
    },
    options: { 
        indexAxis: 'y',
        maintainAspectRatio: false,
        responsive: true, 
        plugins: { legend: { display: false } } 
    }
});

// 2. Gráfico de Materiais
new Chart(document.getElementById('graficoItens'), {
    type: 'bar',
    data: {
        labels: <?= json_encode(array_keys($dados_grafico_itens)) ?>,
        datasets: [{
            label: 'Quantidade Saída',
            data: <?= json_encode(array_values($dados_grafico_itens)) ?>,
            backgroundColor: '#4361ee'
        }]
    },
    options: { responsive: true, plugins: { legend: { display: false } } }
});

// 3. Gráfico de Setores
new Chart(document.getElementById('graficoSetores'), {
    type: 'pie',
    data: {
        labels: <?= json_encode(array_keys($dados_grafico_setores)) ?>,
        datasets: [{
            data: <?= json_encode(array_values($dados_grafico_setores)) ?>,
            backgroundColor: ['#4361ee', '#3f37c9', '#4cc9f0', '#4895ef', '#560bad', '#7209b7']
        }]
    },
    options: { responsive: true }
});
</script>

</body>
</html>