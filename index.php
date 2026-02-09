<?php 
session_start();
include 'db.php';
include 'sessao_monitor.php';

// --- VERIFICAR E CRIAR COLUNA id_unidade_destino SE NECESSÁRIO ---
try {
    $check_column = $pdo->query("SHOW COLUMNS FROM entregas LIKE 'id_unidade_destino'")->fetch();
    if (!$check_column) {
        // Adicionar coluna se não existir
        $pdo->exec("ALTER TABLE entregas ADD COLUMN id_unidade_destino INT NULL DEFAULT 1");
        $pdo->exec("ALTER TABLE entregas ADD CONSTRAINT fk_entregas_unidade FOREIGN KEY (id_unidade_destino) REFERENCES unidades(id_unidade) ON DELETE SET NULL");
    }
} catch(Exception $e) {
    // Ignorar erro se a coluna já existir
}

// --- INÍCIO DA LÓGICA DE INATIVIDADE ---
$tempo_limite = 600; // 10 minutos (em segundos)

if (isset($_SESSION['logado'])) {
    if (isset($_SESSION['ultimo_acesso'])) {
        $tempo_inativo = time() - $_SESSION['ultimo_acesso'];
        
        if ($tempo_inativo > $tempo_limite) {
            // Se passar de 10 min, registra log e desloga
            if(function_exists('registrar_log')) {
                registrar_log('Sessão expirada por inatividade');
            }
            session_unset();
            session_destroy();
            header("Location: login.php?erro=inatividade");
            exit;
        }
    }
    // Atualiza o tempo de acesso a cada clique/refresh
    $_SESSION['ultimo_acesso'] = time();
}

// 1. SEGURANÇA DE ACESSO
if (!isset($_SESSION['logado'])) { 
    header("Location: login.php"); 
    exit; 
}

if (isset($_GET['sair'])) { 
    // Registrar log de logout
    registrar_log('Logout do sistema');
    session_destroy(); 
    header("Location: login.php"); 
    exit; 
}

// --- FUNÇÃO PARA REGISTRAR LOGS ---
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

// Criar tabela de logs se não existir (executa apenas uma vez)
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

// --- 2. VERIFICAÇÃO DE PERGUNTA DE SEGURANÇA ---
$stmt_check_sec = $pdo->prepare("SELECT pergunta_seguranca, resposta_seguranca FROM usuarios WHERE id_usuario = ?");
$stmt_check_sec->execute([$_SESSION['user_id']]);
$user_sec = $stmt_check_sec->fetch();

$precisa_configurar = (empty($user_sec['pergunta_seguranca']) || empty($user_sec['resposta_seguranca']));

if(isset($_POST['configurar_seguranca'])) {
    $p = trim($_POST['p_sec']);
    $r = trim($_POST['r_sec']);
    if(!empty($p) && !empty($r)) {
        $pdo->prepare("UPDATE usuarios SET pergunta_seguranca = ?, resposta_seguranca = ? WHERE id_usuario = ?")
            ->execute([$p, $r, $_SESSION['user_id']]);
        registrar_log('Configurou pergunta de segurança', 'Pergunta: ' . substr($p, 0, 50) . '...');
        header("Location: index.php?sucesso=1");
        exit;
    }
}

// --- 3. LÓGICA DE REQUISIÇÕES (PARA OPERADORES E ADMINS) ---
if(isset($_POST['fazer_pedido'])) {
    $id_item = $_POST['id_item_req'];
    $qtd_pedida = intval($_POST['qtd_req']);
    $id_setor = $_POST['id_setor_req'];
    $id_unidade = $_POST['id_unidade_req']; // NOVO
    $id_usuario = $_SESSION['user_id'];
    $msg_solicitante = trim($_POST['msg_solicitante'] ?? '');

    // VALIDAÇÃO DE QUANTIDADE PARA REQUISIÇÃO (NOVO)
    if($qtd_pedida <= 0) {
        echo "<script>alert('Erro: A quantidade deve ser maior que zero!'); window.location='index.php';</script>";
        exit;
    }
    if($qtd_pedida > 10000) {
        echo "<script>alert('Erro: Quantidade muito alta para uma requisição! Máximo: 10.000 unidades'); window.location='index.php';</script>";
        exit;
    }

    // TRAVA DE ESTOQUE: Verifica saldo antes de permitir a solicitação
    $stmt_v = $pdo->prepare("SELECT quantidade_atual, nome FROM itens WHERE id_item = ?");
    $stmt_v->execute([$id_item]);
    $item_v = $stmt_v->fetch();

    if(!$item_v) {
        echo "<script>alert('Item não encontrado!'); window.location='index.php';</script>";
        exit;
    }

    if($qtd_pedida > $item_v['quantidade_atual']) {
        echo "<script>alert('Estoque insuficiente! O item \\\"".addslashes($item_v['nome'])."\\\" possui apenas {$item_v['quantidade_atual']} unidades disponíveis.'); window.location='index.php';</script>";
        exit;
    }

    // INSERIR COM MENSAGEM DO SOLICITANTE E UNIDADE
    $stmt = $pdo->prepare("INSERT INTO requisicoes (id_item, id_setor, id_unidade_origem, id_usuario, quantidade_pedida, mensagem_solicitante) VALUES (?, ?, ?, ?, ?, ?)");
    if($stmt->execute([$id_item, $id_setor, $id_unidade, $id_usuario, $qtd_pedida, $msg_solicitante])) {
        
        // Buscar nome da unidade para o log
        $stmt_uni = $pdo->prepare("SELECT nome_unidade FROM unidades WHERE id_unidade = ?");
        $stmt_uni->execute([$id_unidade]);
        $unidade_nome = $stmt_uni->fetch()['nome_unidade'] ?? 'Desconhecida';
        
        registrar_log('Nova requisição criada', 'Item: ' . $item_v['nome'] . ' | Qtd: ' . $qtd_pedida . ' | Unidade: ' . $unidade_nome);
        header("Location: index.php?sucesso=pedido");
        exit;
    } else {
        echo "<script>alert('Erro ao registrar pedido!'); window.location='index.php';</script>";
        exit;
    }
}

// LÓGICA PARA CANCELAR/RECUSAR PEDIDO COM MENSAGEM
if(isset($_GET['cancelar_req'])) {
    $id_req = $_GET['cancelar_req'];
    $resp_adm = isset($_GET['resp']) ? trim($_GET['resp']) : "Pedido recusado.";

    if($_SESSION['nivel'] == 'admin') {
        // Buscar info da requisição antes de atualizar para o log
        $stmt_info = $pdo->prepare("SELECT r.*, i.nome as nome_item FROM requisicoes r JOIN itens i ON r.id_item = i.id_item WHERE r.id_requisicao = ?");
        $stmt_info->execute([$id_req]);
        $req_info = $stmt_info->fetch();
        
        // Admin recusa: mantém registro com motivo
        $pdo->prepare("UPDATE requisicoes SET status = 'cancelado', mensagem_adm = ? WHERE id_requisicao = ?")
            ->execute([$resp_adm, $id_req]);
        
        if($req_info) {
            registrar_log('Requisição recusada (admin)', 'ID: ' . $id_req . ' | Item: ' . $req_info['nome_item'] . ' | Qtd: ' . $req_info['quantidade_pedida'] . ' | Motivo: ' . substr($resp_adm, 0, 100));
        }
    } else {
        // Buscar info da requisição antes de deletar para o log
        $stmt_info = $pdo->prepare("SELECT r.*, i.nome as nome_item FROM requisicoes r JOIN itens i ON r.id_item = i.id_item WHERE r.id_requisicao = ? AND r.id_usuario = ?");
        $stmt_info->execute([$id_req, $_SESSION['user_id']]);
        $req_info = $stmt_info->fetch();
        
        // Usuário cancela: deleta se ainda estiver pendente
        $pdo->prepare("DELETE FROM requisicoes WHERE id_requisicao = ? AND id_usuario = ? AND status = 'pendente'")
            ->execute([$id_req, $_SESSION['user_id']]);
        
        if($req_info) {
            registrar_log('Requisição cancelada (usuário)', 'ID: ' . $id_req . ' | Item: ' . $req_info['nome_item'] . ' | Qtd: ' . $req_info['quantidade_pedida']);
        }
    }
    header("Location: index.php?cancelado=1");
    exit;
}

// LÓGICA PARA ATENDER PEDIDO COM MENSAGEM (SOMENTE ADMIN) - CORRIGIDO
if(isset($_GET['atender_req']) && $_SESSION['nivel'] == 'admin') {
    $id_req = $_GET['atender_req'];
    $resp_adm = isset($_GET['resp']) ? trim($_GET['resp']) : "Pedido atendido com sucesso.";
    
    // Buscar requisição com nome do solicitante E UNIDADE
    $stmt = $pdo->prepare("SELECT r.*, u.nome as nome_solicitante, i.nome as nome_item, r.id_unidade_origem
                           FROM requisicoes r 
                           JOIN usuarios u ON r.id_usuario = u.id_usuario 
                           JOIN itens i ON r.id_item = i.id_item 
                           WHERE r.id_requisicao = ? AND r.status = 'pendente'");
    $stmt->execute([$id_req]);
    $req = $stmt->fetch();

    if(!$req) {
        echo "<script>alert('Requisição não encontrada ou já processada!'); window.location='index.php';</script>";
        exit;
    }

    $stmt_item = $pdo->prepare("SELECT quantidade_atual, nome FROM itens WHERE id_item = ?");
    $stmt_item->execute([$req['id_item']]);
    $item = $stmt_item->fetch();

    if(!$item || $item['quantidade_atual'] < $req['quantidade_pedida']) {
        $nome_item = $item ? addslashes($item['nome']) : 'Item não encontrado';
        $disponivel = $item ? $item['quantidade_atual'] : 0;
        echo "<script>alert('Estoque insuficiente! O item \\\"{$nome_item}\\\" possui apenas {$disponivel} unidades disponíveis.'); window.location='index.php';</script>";
        exit;
    }

    // INICIAR TRANSAÇÃO PARA GARANTIR CONSISTÊNCIA
    try {
        $pdo->beginTransaction();

        // 1. Baixa o estoque
        $pdo->prepare("UPDATE itens SET quantidade_atual = quantidade_atual - ? WHERE id_item = ?")
            ->execute([$req['quantidade_pedida'], $req['id_item']]);
        
        // 2. Registra entrega - AGORA COM UNIDADE
        $pdo->prepare("INSERT INTO entregas (id_item, id_setor, id_unidade_destino, funcionario, quantidade_entregue, id_admin_processo) VALUES (?, ?, ?, ?, ?, ?)")
            ->execute([$req['id_item'], $req['id_setor'], $req['id_unidade_origem'], $req['nome_solicitante'], $req['quantidade_pedida'], $_SESSION['user_id']]);
        
        // 3. Atualiza status da requisição com mensagem do admin
        $pdo->prepare("UPDATE requisicoes SET status = 'atendido', mensagem_adm = ? WHERE id_requisicao = ?")
            ->execute([$resp_adm, $id_req]);
        
        $pdo->commit();
        
        // Registrar log com unidade
        $stmt_uni = $pdo->prepare("SELECT nome_unidade FROM unidades WHERE id_unidade = ?");
        $stmt_uni->execute([$req['id_unidade_origem']]);
        $unidade_nome = $stmt_uni->fetch()['nome_unidade'] ?? 'Desconhecida';
        
        registrar_log('Requisição atendida', 'ID: ' . $id_req . ' | Item: ' . $req['nome_item'] . ' | Qtd: ' . $req['quantidade_pedida'] . ' | Solicitante: ' . $req['nome_solicitante'] . ' | Unidade: ' . $unidade_nome);
        
        header("Location: index.php?sucesso=1");
        exit;

    } catch(Exception $e) {
        $pdo->rollBack();
        registrar_log('ERRO ao atender requisição', 'ID: ' . $id_req . ' | Erro: ' . $e->getMessage());
        echo "<script>alert('Erro no processamento: " . addslashes($e->getMessage()) . "'); window.location='index.php';</script>";
        exit;
    }
}

// --- 4. LÓGICA ADMINISTRATIVA ---
if($_SESSION['nivel'] == 'admin') {
    // Adicionar Setor
    if(isset($_POST['add_setor'])) {
        $nome_setor = trim($_POST['nome_setor']);
        $check = $pdo->prepare("SELECT id_setor FROM setores WHERE LOWER(nome_setor) = LOWER(?)");
        $check->execute([$nome_setor]);
        if(!$check->fetch()) {
            $pdo->prepare("INSERT INTO setores (nome_setor) VALUES (?)")->execute([$nome_setor]);
            registrar_log('Novo setor adicionado', 'Nome: ' . $nome_setor);
            header("Location: index.php?sucesso=1");
            exit;
        }
    }

    // Editar Setor
    if(isset($_POST['editar_setor'])) {
        $nome_antigo = '';
        // Buscar nome antigo para log
        $stmt_antigo = $pdo->prepare("SELECT nome_setor FROM setores WHERE id_setor = ?");
        $stmt_antigo->execute([$_POST['id_setor_edit']]);
        $antigo = $stmt_antigo->fetch();
        if($antigo) $nome_antigo = $antigo['nome_setor'];
        
        $pdo->prepare("UPDATE setores SET nome_setor = ? WHERE id_setor = ?")
            ->execute([trim($_POST['nome_setor_edit']), $_POST['id_setor_edit']]);
        
        registrar_log('Setor editado', 'ID: ' . $_POST['id_setor_edit'] . ' | De: ' . $nome_antigo . ' | Para: ' . $_POST['nome_setor_edit']);
        
        header("Location: index.php?sucesso=1");
        exit;
    }

    // Excluir Setor (COM MENSAGEM DE AVISO)
    if(isset($_GET['del_setor'])) {
        $id_setor_del = $_GET['del_setor'];
        $check_vinculo = $pdo->prepare("SELECT id_entrega FROM entregas WHERE id_setor = ? LIMIT 1");
        $check_vinculo->execute([$id_setor_del]);
        
        if($check_vinculo->fetch()) {
            echo "<script>alert('Não é possível excluir este setor pois ele possui registros de entregas vinculados! Sugerimos apenas renomear se necessário.'); window.location='index.php';</script>";
            exit;
        } else {
            // Buscar nome do setor para log
            $stmt_nome = $pdo->prepare("SELECT nome_setor FROM setores WHERE id_setor = ?");
            $stmt_nome->execute([$id_setor_del]);
            $setor_nome = $stmt_nome->fetch();
            
            $pdo->prepare("DELETE FROM setores WHERE id_setor = ?")->execute([$id_setor_del]);
            
            if($setor_nome) {
                registrar_log('Setor excluído', 'ID: ' . $id_setor_del . ' | Nome: ' . $setor_nome['nome_setor']);
            }
            
            header("Location: index.php?sucesso=1");
            exit;
        }
    }

    // Adicionar Material
    if(isset($_POST['add_item'])) {
        $nome = trim($_POST['nome_item']); 
        $qtd = intval($_POST['qtd_item']);
        
        // VALIDAÇÃO DE QUANTIDADE PARA ENTRADA DE MATERIAL (NOVO)
        if($qtd <= 0) {
            echo "<script>alert('Erro: A quantidade deve ser maior que zero!'); window.location='index.php';</script>";
            exit;
        }
        if($qtd > 100000) {
            echo "<script>alert('Erro: Quantidade muito alta para uma entrada! Máximo: 100.000 unidades'); window.location='index.php';</script>";
            exit;
        }
        
        $check = $pdo->prepare("SELECT id_item FROM itens WHERE nome = ?");
        $check->execute([$nome]); 
        $exist = $check->fetch();
        
        if($exist) { 
            $pdo->prepare("UPDATE itens SET quantidade_atual = quantidade_atual + ?, ativo = 1 WHERE id_item = ?")
                ->execute([$qtd, $exist['id_item']]); 
            registrar_log('Entrada de material', 'Item ID: ' . $exist['id_item'] . ' | Nome: ' . $nome . ' | Qtd: +' . $qtd);
        } else { 
            $pdo->prepare("INSERT INTO itens (nome, quantidade_atual, ativo) VALUES (?, ?, 1)")
                ->execute([$nome, $qtd]); 
            registrar_log('Novo item cadastrado', 'Nome: ' . $nome . ' | Qtd inicial: ' . $qtd);
        }
        header("Location: index.php?sucesso=1");
        exit;
    }

    // Saída Direta (Balcão) - CORRIGIDO PARA SALVAR UNIDADE
    if(isset($_POST['entrega'])) {
        $id_item = $_POST['id_item'];
        $qtd_saida = intval($_POST['qtd_saida']);
        $id_setor = $_POST['id_setor'];
        $funcionario = $_POST['funcionario'];

        // VALIDAÇÃO DE QUANTIDADE PARA SAÍDA DIRETA (NOVO)
        if($qtd_saida <= 0) {
            echo "<script>alert('Erro: A quantidade deve ser maior que zero!'); window.location='index.php';</script>";
            exit;
        }
        if($qtd_saida > 10000) {
            echo "<script>alert('Erro: Quantidade muito alta para uma saída direta! Máximo: 10.000 unidades'); window.location='index.php';</script>";
            exit;
        }

        $stmt_v = $pdo->prepare("SELECT quantidade_atual, nome FROM itens WHERE id_item = ?");
        $stmt_v->execute([$id_item]);
        $item_v = $stmt_v->fetch();

        if(!$item_v) {
            echo "<script>alert('Item não encontrado!'); window.location='index.php';</script>";
            exit;
        }

        if($qtd_saida > $item_v['quantidade_atual']) {
            echo "<script>alert('Estoque insuficiente! Você tentou retirar {$qtd_saida} de \\\"".addslashes($item_v['nome'])."\\\", mas o saldo é {$item_v['quantidade_atual']}.'); window.location='index.php';</script>";
            exit;
        }

        // Obter unidade do admin que está fazendo a saída
        $stmt_admin = $pdo->prepare("SELECT id_unidade FROM usuarios WHERE id_usuario = ?");
        $stmt_admin->execute([$_SESSION['user_id']]);
        $admin_unidade = $stmt_admin->fetch();
        $id_unidade_destino = $admin_unidade ? $admin_unidade['id_unidade'] : 1;

        try {
            $pdo->beginTransaction();
            
            $pdo->prepare("UPDATE itens SET quantidade_atual = quantidade_atual - ? WHERE id_item = ?")
                ->execute([$qtd_saida, $id_item]);
                
            // INSERIR COM UNIDADE - CORRIGIDO
            $pdo->prepare("INSERT INTO entregas (id_item, id_setor, id_unidade_destino, funcionario, quantidade_entregue, id_admin_processo) VALUES (?, ?, ?, ?, ?, ?)")
                ->execute([$id_item, $id_setor, $id_unidade_destino, $funcionario, $qtd_saida, $_SESSION['user_id']]);
            
            $pdo->commit();
            
            // Buscar nome da unidade para o log
            $stmt_uni = $pdo->prepare("SELECT nome_unidade FROM unidades WHERE id_unidade = ?");
            $stmt_uni->execute([$id_unidade_destino]);
            $unidade_nome = $stmt_uni->fetch()['nome_unidade'] ?? 'Desconhecida';
            
            registrar_log('Saída direta de material', 'Item: ' . $item_v['nome'] . ' | Qtd: ' . $qtd_saida . ' | Setor: ' . $id_setor . ' | Responsável: ' . $funcionario . ' | Unidade: ' . $unidade_nome);
            
            header("Location: index.php?sucesso=1");
            exit;

        } catch(Exception $e) {
            $pdo->rollBack();
            registrar_log('ERRO na saída direta', 'Item ID: ' . $id_item . ' | Erro: ' . $e->getMessage());
            echo "<script>alert('Erro na saída direta: " . addslashes($e->getMessage()) . "'); window.location='index.php';</script>";
            exit;
        }
    }

    // Editar Material
    if(isset($_POST['salvar_edicao_item'])) {
        // VALIDAÇÃO DE QUANTIDADE PARA EDIÇÃO DE ITEM (NOVO)
        $qtd_edit = intval($_POST['qtd_edit']);
        if($qtd_edit < 0) {
            echo "<script>alert('Erro: A quantidade não pode ser negativa!'); window.location='index.php';</script>";
            exit;
        }
        if($qtd_edit > 1000000) {
            echo "<script>alert('Erro: Quantidade muito alta! Máximo: 1.000.000 unidades'); window.location='index.php';</script>";
            exit;
        }
        
        // Buscar dados antigos para log
        $stmt_antigo = $pdo->prepare("SELECT nome, quantidade_atual FROM itens WHERE id_item = ?");
        $stmt_antigo->execute([$_POST['id_item_edit']]);
        $antigo = $stmt_antigo->fetch();
        
        $pdo->prepare("UPDATE itens SET nome=?, quantidade_atual=? WHERE id_item=?")
            ->execute([$_POST['nome_edit'], $qtd_edit, $_POST['id_item_edit']]);
        
        if($antigo) {
            $detalhes_log = 'ID: ' . $_POST['id_item_edit'];
            $detalhes_log .= ' | Nome: ' . $antigo['nome'] . ' → ' . $_POST['nome_edit'];
            $detalhes_log .= ' | Qtd: ' . $antigo['quantidade_atual'] . ' → ' . $qtd_edit;
            registrar_log('Item editado', $detalhes_log);
        }
        
        header("Location: index.php?sucesso=1");
        exit;
    }

    if(isset($_GET['desativar'])) {
        // Buscar nome do item para log
        $stmt_nome = $pdo->prepare("SELECT nome FROM itens WHERE id_item = ?");
        $stmt_nome->execute([$_GET['desativar']]);
        $item_nome = $stmt_nome->fetch();
        
        $pdo->prepare("UPDATE itens SET ativo = 0 WHERE id_item = ?")
            ->execute([$_GET['desativar']]);
        
        if($item_nome) {
            registrar_log('Item desativado', 'ID: ' . $_GET['desativar'] . ' | Nome: ' . $item_nome['nome']);
        }
        
        header("Location: index.php?sucesso=1");
        exit;
    }

    // Excluir Histórico
    if(isset($_GET['del_hist'])) {
        // Buscar info da entrega para log
        $stmt_info = $pdo->prepare("SELECT e.*, i.nome as nome_item FROM entregas e LEFT JOIN itens i ON e.id_item = i.id_item WHERE e.id_entrega = ?");
        $stmt_info->execute([$_GET['del_hist']]);
        $entrega_info = $stmt_info->fetch();
        
        $pdo->prepare("DELETE FROM entregas WHERE id_entrega = ?")->execute([$_GET['del_hist']]);
        
        if($entrega_info) {
            registrar_log('Histórico excluído', 'Entrega ID: ' . $_GET['del_hist'] . ' | Item: ' . ($entrega_info['nome_item'] ?? 'Desconhecido') . ' | Qtd: ' . $entrega_info['quantidade_entregue']);
        }
        
        header("Location: index.php?sucesso=1");
        exit;
    }

    // =============== MODIFICAÇÃO 1: CADASTRO DE USUÁRIO COM UNIDADE ===============
    if(isset($_POST['add_usuario'])) {
        $pass = password_hash($_POST['u_pass'], PASSWORD_DEFAULT);
        $id_unidade = intval($_POST['u_unidade']);
        
        // Buscar nome da unidade para o log
        $stmt_uni = $pdo->prepare("SELECT nome_unidade FROM unidades WHERE id_unidade = ?");
        $stmt_uni->execute([$id_unidade]);
        $unidade_nome = $stmt_uni->fetch()['nome_unidade'] ?? 'Desconhecida';
        
        $stmt = $pdo->prepare("INSERT INTO usuarios (nome, usuario, senha, nivel, id_unidade, pergunta_seguranca, resposta_seguranca) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([
            trim($_POST['u_nome']), 
            trim($_POST['u_user']), 
            $pass, 
            $_POST['u_nivel'], 
            $id_unidade,
            trim($_POST['u_pergunta']), 
            trim($_POST['u_resposta'])
        ]);
        
        registrar_log('Novo usuário cadastrado', 'Nome: ' . $_POST['u_nome'] . ' | Login: ' . $_POST['u_user'] . ' | Nível: ' . $_POST['u_nivel'] . ' | Unidade: ' . $unidade_nome);
        
        header("Location: index.php?sucesso=1");
        exit;
    }

    // =============== MODIFICAÇÃO 2: EDIÇÃO DE USUÁRIO COM UNIDADE ===============
    if(isset($_POST['edit_usuario'])) {
        $id_unidade_edit = intval($_POST['u_unidade_edit']);
        
        // Buscar dados antigos para log
        $stmt_antigo = $pdo->prepare("SELECT u.*, un.nome_unidade as unidade_antiga 
                                      FROM usuarios u 
                                      LEFT JOIN unidades un ON u.id_unidade = un.id_unidade 
                                      WHERE u.id_usuario = ?");
        $stmt_antigo->execute([$_POST['id_u_edit']]);
        $antigo = $stmt_antigo->fetch();
        
        // Buscar nome da nova unidade para log
        $stmt_nova_uni = $pdo->prepare("SELECT nome_unidade FROM unidades WHERE id_unidade = ?");
        $stmt_nova_uni->execute([$id_unidade_edit]);
        $nova_unidade_nome = $stmt_nova_uni->fetch()['nome_unidade'] ?? 'Desconhecida';
        
        if(!empty($_POST['u_pass_edit'])) {
            $pass = password_hash($_POST['u_pass_edit'], PASSWORD_DEFAULT);
            $pdo->prepare("UPDATE usuarios SET nome=?, nivel=?, id_unidade=?, senha=? WHERE id_usuario=?")
                ->execute([
                    trim($_POST['u_nome_edit']), 
                    $_POST['u_nivel_edit'], 
                    $id_unidade_edit, 
                    $pass, 
                    $_POST['id_u_edit']
                ]);
        } else {
            $pdo->prepare("UPDATE usuarios SET nome=?, nivel=?, id_unidade=? WHERE id_usuario=?")
                ->execute([
                    trim($_POST['u_nome_edit']), 
                    $_POST['u_nivel_edit'], 
                    $id_unidade_edit, 
                    $_POST['id_u_edit']
                ]);
        }
        
        if($antigo) {
            $detalhes_log = 'ID: ' . $_POST['id_u_edit'];
            $detalhes_log .= ' | Nome: ' . $antigo['nome'] . ' → ' . $_POST['u_nome_edit'];
            $detalhes_log .= ' | Nível: ' . $antigo['nivel'] . ' → ' . $_POST['u_nivel_edit'];
            $detalhes_log .= ' | Unidade: ' . ($antigo['unidade_antiga'] ?? 'Nenhuma') . ' → ' . $nova_unidade_nome;
            if(!empty($_POST['u_pass_edit'])) {
                $detalhes_log .= ' | Senha alterada';
            }
            registrar_log('Usuário editado', $detalhes_log);
        }
        
        header("Location: index.php?sucesso=1");
        exit;
    }

    // EXCLUSÃO DE USUÁRIO
    if(isset($_GET['del_u'])) {
        $id_del = $_GET['del_u'];
        if($id_del != $_SESSION['user_id']) {
            // Buscar dados do usuário para log
            $stmt_info = $pdo->prepare("SELECT u.*, un.nome_unidade FROM usuarios u LEFT JOIN unidades un ON u.id_unidade = un.id_unidade WHERE u.id_usuario = ?");
            $stmt_info->execute([$id_del]);
            $usuario_info = $stmt_info->fetch();
            
            $pdo->prepare("DELETE FROM usuarios WHERE id_usuario = ?")->execute([$id_del]);
            
            if($usuario_info) {
                registrar_log('Usuário excluído', 'ID: ' . $id_del . ' | Nome: ' . $usuario_info['nome'] . ' | Login: ' . $usuario_info['usuario'] . ' | Nível: ' . $usuario_info['nivel'] . ' | Unidade: ' . ($usuario_info['nome_unidade'] ?? 'Nenhuma'));
            }
            
            header("Location: index.php?sucesso=1");
            exit;
        } else {
            echo "<script>alert('Você não pode excluir seu próprio acesso administrativo!'); window.location='index.php';</script>";
            exit;
        }
    }
}

// =============== MODIFICAÇÃO 3: BUSCA DE USUÁRIOS COM UNIDADE ===============
// CONFIGURAÇÃO DE PAGINAÇÃO PARA ITENS
$itens_por_pagina = 100; // Mostrar 100 itens por página
$pagina_atual = isset($_GET['pagina_itens']) ? max(1, intval($_GET['pagina_itens'])) : 1;
$offset_itens = ($pagina_atual - 1) * $itens_por_pagina;

// Buscar itens com paginação
$stmt_itens = $pdo->prepare("SELECT * FROM itens WHERE ativo = 1 ORDER BY nome ASC LIMIT ? OFFSET ?");
$stmt_itens->bindValue(1, $itens_por_pagina, PDO::PARAM_INT);
$stmt_itens->bindValue(2, $offset_itens, PDO::PARAM_INT);
$stmt_itens->execute();
$itens = $stmt_itens->fetchAll(PDO::FETCH_ASSOC);

// Contar total de itens ativos
$total_itens = $pdo->query("SELECT COUNT(*) as total FROM itens WHERE ativo = 1")->fetch()['total'];
$total_paginas_itens = ceil($total_itens / $itens_por_pagina);

$setores = $pdo->query("SELECT * FROM setores ORDER BY nome_setor ASC")->fetchAll(PDO::FETCH_ASSOC);

// HISTÓRICO COM UNIDADE - CORRIGIDO
$historico = $pdo->query("
    SELECT e.*, i.nome as nome_item, s.nome_setor, un.nome_unidade 
    FROM entregas e 
    LEFT JOIN itens i ON e.id_item = i.id_item 
    JOIN setores s ON e.id_setor = s.id_setor 
    LEFT JOIN unidades un ON e.id_unidade_destino = un.id_unidade 
    ORDER BY e.data_entrega DESC
")->fetchAll(PDO::FETCH_ASSOC);

$usuarios_lista = $pdo->query("
    SELECT u.*, un.nome_unidade 
    FROM usuarios u 
    LEFT JOIN unidades un ON u.id_unidade = un.id_unidade 
    ORDER BY u.nome ASC
")->fetchAll(PDO::FETCH_ASSOC);

// PRIVACIDADE: Operador vê apenas as suas, Admin vê todas (INCLUINDO MENSAGENS)
if ($_SESSION['nivel'] == 'admin') {
    $sql_pedidos = "SELECT r.*, i.nome as nome_item, s.nome_setor, u.nome as nome_solicitante, 
                           un.nome_unidade as nome_unidade
                    FROM requisicoes r 
                    JOIN itens i ON r.id_item = i.id_item 
                    JOIN setores s ON r.id_setor = s.id_setor 
                    JOIN usuarios u ON r.id_usuario = u.id_usuario 
                    JOIN unidades un ON r.id_unidade_origem = un.id_unidade 
                    WHERE r.status = 'pendente' 
                    ORDER BY r.data_pedido ASC";
    $pedidos_pendentes = $pdo->query($sql_pedidos)->fetchAll(PDO::FETCH_ASSOC);
    
    // Buscar histórico de requisições finalizadas
    $sql_finalizadas = "SELECT r.*, i.nome as nome_item, s.nome_setor, u.nome as nome_solicitante 
                       FROM requisicoes r 
                       JOIN itens i ON r.id_item = i.id_item 
                       JOIN setores s ON r.id_setor = s.id_setor 
                       JOIN usuarios u ON r.id_usuario = u.id_usuario 
                       WHERE r.status IN ('atendido', 'cancelado')
                       ORDER BY r.data_pedido DESC LIMIT 50";
    $requisicoes_finalizadas = $pdo->query($sql_finalizadas)->fetchAll(PDO::FETCH_ASSOC);
} else {
    $sql_pedidos = "SELECT r.*, i.nome as nome_item, s.nome_setor, u.nome as nome_solicitante,
                           un.nome_unidade as nome_unidade
                    FROM requisicoes r 
                    JOIN itens i ON r.id_item = i.id_item 
                    JOIN setores s ON r.id_setor = s.id_setor 
                    JOIN usuarios u ON r.id_usuario = u.id_usuario 
                    JOIN unidades un ON r.id_unidade_origem = un.id_unidade 
                    WHERE r.status = 'pendente' AND r.id_usuario = ?
                    ORDER BY r.data_pedido ASC";
    $stmt_p = $pdo->prepare($sql_pedidos);
    $stmt_p->execute([$_SESSION['user_id']]);
    $pedidos_pendentes = $stmt_p->fetchAll(PDO::FETCH_ASSOC);
    
    // Histórico do próprio usuário
    $sql_minhas = "SELECT r.*, i.nome as nome_item, s.nome_setor 
                  FROM requisicoes r 
                  JOIN itens i ON r.id_item = i.id_item 
                  JOIN setores s ON r.id_setor = s.id_setor 
                  WHERE r.id_usuario = ? AND r.status IN ('atendido', 'cancelado')
                  ORDER BY r.data_pedido DESC LIMIT 20";
    $stmt_m = $pdo->prepare($sql_minhas);
    $stmt_m->execute([$_SESSION['user_id']]);
    $minhas_requisicoes = $stmt_m->fetchAll(PDO::FETCH_ASSOC);
}

$total_pendente = count($pedidos_pendentes);
$criticos = array_filter($itens, function($i) { return $i['quantidade_atual'] <= 5; });
?>

<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Almoxarifado Saúde Pro</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body { background: #f8fafc; font-family: 'Inter', sans-serif; }
        .navbar { background: linear-gradient(135deg, #4361ee, #3f37c9); }
        .card { border: none; border-radius: 12px; box-shadow: 0 5px 15px rgba(0,0,0,0.02); margin-bottom: 20px; }
        .btn-notificacao { position: relative; background: none; border: none; color: white; cursor: pointer; }
        .badge-critico { background-color: #ff4757; animation: blinker 1.5s linear infinite; }
        .mensagem-box { background: #f8f9fa; border-left: 4px solid #4361ee; padding: 10px; margin: 5px 0; border-radius: 4px; }
        .mensagem-admin { border-left-color: #28a745; }
        .mensagem-solicitante { border-left-color: #ffc107; }
        
        /* ============ ESTILOS PARA ALERTA CRÍTICO COMPACTO ============ */
        .alerta-estoque-critico-compacto {
            animation: pulseCritical 2s infinite;
            border: none !important;
            position: relative;
            overflow: hidden;
            background: linear-gradient(135deg, #2d3436, #636e72) !important;
            color: white !important;
        }

        @keyframes pulseCritical {
            0% {
                box-shadow: 0 0 0 0 rgba(220, 53, 69, 0.7);
                transform: scale(1);
            }
            50% {
                box-shadow: 0 0 0 10px rgba(220, 53, 69, 0);
                transform: scale(1.005);
            }
            100% {
                box-shadow: 0 0 0 0 rgba(220, 53, 69, 0);
                transform: scale(1);
            }
        }

        /* BADGE PISCANTE */
        .badge-pulsante {
            animation: badgePulse 1.5s infinite;
            position: relative;
        }

        @keyframes badgePulse {
            0%, 100% {
                background-color: #dc3545;
                transform: scale(1);
            }
            50% {
                background-color: #ff4757;
                transform: scale(1.1);
                box-shadow: 0 0 10px rgba(255, 71, 87, 0.5);
            }
        }

        /* ÍCONE VIBRANDO */
        .icon-vibrate {
            animation: vibrate 0.5s infinite;
            display: inline-block;
        }

        @keyframes vibrate {
            0%, 100% {
                transform: rotate(0deg);
            }
            25% {
                transform: rotate(-5deg);
            }
            75% {
                transform: rotate(5deg);
            }
        }

        /* PROGRESS BAR ANIMADO */
        .progress-bar-critico {
            background: linear-gradient(90deg, #dc3545, #ff4757, #dc3545);
            background-size: 200% 100%;
            animation: shimmer 2s infinite linear;
        }

        @keyframes shimmer {
            0% {
                background-position: -200% 0;
            }
            100% {
                background-position: 200% 0;
            }
        }

        /* ALERTA EM DESTAQUE */
        .item-critico-destaque {
            background: linear-gradient(90deg, rgba(220, 53, 69, 0.1), rgba(220, 53, 69, 0.05), rgba(220, 53, 69, 0.1));
            background-size: 200% 100%;
            animation: slideBg 3s infinite linear;
            border-left: 4px solid #dc3545 !important;
        }

        @keyframes slideBg {
            0% {
                background-position: 200% 0;
            }
            100% {
                background-position: -200% 0;
            }
        }
        
        /* ESTILOS PARA PAGINAÇÃO */
        .pagination .page-item.active .page-link {
            background-color: #4361ee;
            border-color: #4361ee;
        }
        .pagination .page-link {
            color: #4361ee;
        }
        .pagination .page-link:hover {
            background-color: #e9ecef;
        }
        
        @keyframes blinker { 50% { opacity: 0.6; } }
        @media print { .no-print { display: none !important; } }
        
        /* NOVO ESTILO PARA PROGRESS BAR PEQUENO */
        .progress-micro {
            height: 3px;
            background: rgba(255,255,255,0.1);
            margin-top: 2px;
        }
        
        /* ESTILO PARA ITEM CRÍTICO NA LISTA CONDENSADA */
        .critico-item-compact {
            transition: all 0.3s;
            padding: 4px 8px;
            border-radius: 4px;
            margin-bottom: 2px;
        }
        
        .critico-item-compact:hover {
            background: rgba(255,255,255,0.1);
        }

        /* ============ NOVOS ESTILOS PARA BUSCA EM TEMPO REAL ============ */
        .searchable-dropdown {
            position: relative;
        }

        .dropdown-results {
            position: absolute;
            top: 100%;
            left: 0;
            right: 0;
            background: white;
            border: 1px solid #dee2e6;
            border-radius: 8px;
            max-height: 300px;
            overflow-y: auto;
            z-index: 1050;
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
            display: none;
        }

        .dropdown-item-result {
            padding: 8px 12px;
            cursor: pointer;
            transition: background 0.2s;
            border-bottom: 1px solid #f8f9fa;
            color: #212529 !important;
        }

        .dropdown-item-result:hover {
            background-color: #f8f9fa;
        }

        .dropdown-item-result:active {
            background-color: #e9ecef;
        }

        .dropdown-item-result strong {
            font-size: 0.9rem;
            color: #212529 !important;
        }

        .dropdown-item-result small {
            font-size: 0.8rem;
            color: #6c757d !important;
        }

        .dropdown-item-result .badge {
            color: #fff !important;
        }

        /* Botão flutuante para busca global */
        .btn-busca-global {
            position: fixed;
            bottom: 20px;
            right: 20px;
            width: 60px;
            height: 60px;
            border-radius: 50%;
            background: linear-gradient(135deg, #4361ee, #3f37c9);
            color: white;
            border: none;
            box-shadow: 0 4px 15px rgba(67, 97, 238, 0.3);
            z-index: 1000;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
        }

        .btn-busca-global:hover {
            transform: scale(1.1);
            box-shadow: 0 6px 20px rgba(67, 97, 238, 0.4);
            background: linear-gradient(135deg, #3f37c9, #4361ee);
        }

        /* Estilo para resultados de busca */
        .search-highlight {
            background-color: #fff3cd;
            font-weight: bold;
        }
    </style>
</head>
<body>

<nav class="navbar navbar-dark mb-4 no-print p-3 shadow">
    <div class="container d-flex justify-content-between align-items-center">
        <span class="navbar-brand fw-bold">
            <i class="fa-solid fa-hospital me-2"></i> Almoxarifado Saúde
            <?php if($total_pendente > 0): ?>
                <button class="btn-notificacao ms-2" data-bs-toggle="modal" data-bs-target="#modalNotificacoes">
                    <i class="fa-solid fa-bell"></i>
                    <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger" style="font-size: 0.6rem;">
                        <?= htmlspecialchars($total_pendente) ?>
                    </span>
                </button>
            <?php endif; ?>
        </span>
        <div class="text-white">
            <span class="me-3 small">Olá, <strong><?= htmlspecialchars($_SESSION['nome']) ?></strong></span>
            <?php if($_SESSION['nivel'] == 'admin'): ?>
                <?php if(!empty($criticos)): ?>
                <a href="estoque_critico.php" class="btn btn-danger btn-sm me-2 position-relative">
                    <i class="fa-solid fa-triangle-exclamation me-1"></i> Estoque Crítico
                    <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-white text-danger border border-danger" style="font-size: 0.6rem;">
                        <?= count($criticos) ?>
                    </span>
                </a>
                <?php endif; ?>
                <a href="logs.php" class="btn btn-outline-light btn-sm me-2"><i class="fa-solid fa-clipboard-list me-1"></i> Logs</a>
                <a href="unidades.php" class="btn btn-outline-light btn-sm me-2"><i class="fa-solid fa-hospital me-1"></i> Unidades</a>
            <?php endif; ?>
            <a href="?sair" class="btn btn-danger btn-sm">Sair</a>
        </div>
    </div>
</nav>

<!-- Modal para busca global de itens -->
<div class="modal fade" id="modalBuscaItem" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title"><i class="fa-solid fa-search me-2"></i>Buscar Item no Estoque</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <input type="text" id="buscaGlobalItem" class="form-control" 
                           placeholder="Digite o nome do material...">
                </div>
                <div class="table-responsive" style="max-height: 400px; overflow-y: auto;">
                    <table class="table table-sm table-hover">
                        <thead>
                            <tr>
                                <th>Material</th>
                                <th class="text-center">Estoque</th>
                                <th class="text-center">Status</th>
                                <th>Ação</th>
                            </tr>
                        </thead>
                        <tbody id="resultadosBuscaGlobal">
                            <tr>
                                <td colspan="4" class="text-center py-4 text-muted">
                                    <i class="fa-solid fa-search fa-lg mb-2"></i><br>
                                    Digite acima para buscar itens
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="modalNotificacoes" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content border-0 shadow-lg">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title fw-bold"><i class="fa-solid fa-bell me-2"></i>Solicitações Pendentes</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-0">
                <div class="list-group list-group-flush">
                    <?php foreach($pedidos_pendentes as $p): ?>
                        <div class="list-group-item p-3">
                            <div class="d-flex w-100 justify-content-between">
                                <h6 class="mb-1 fw-bold text-primary"><?= htmlspecialchars($p['nome_item']) ?> (<?= htmlspecialchars($p['quantidade_pedida']) ?> un)</h6>
                                <small class="text-muted"><?= date('d/m H:i', strtotime($p['data_pedido'])) ?></small>
                            </div>
                            <p class="mb-1 small">Solicitado por: <strong><?= htmlspecialchars($p['nome_solicitante']) ?></strong></p>
                            <small class="text-muted">Setor: <?= htmlspecialchars($p['nome_setor']) ?></small>
                            <?php if(!empty($p['mensagem_solicitante'])): ?>
                                <div class="mensagem-box mensagem-solicitante mt-2 small">
                                    <strong><i class="fa-solid fa-comment-dots me-1"></i>Observação:</strong><br>
                                    <?= htmlspecialchars($p['mensagem_solicitante']) ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                    <?php if(empty($pedidos_pendentes)): ?>
                        <div class="list-group-item p-4 text-center text-muted">
                            <i class="fa-solid fa-check-circle fa-2x mb-3 text-success"></i>
                            <p class="mb-0">Nenhuma requisição pendente no momento.</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            <div class="modal-footer bg-light">
                <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Fechar Janela</button>
            </div>
        </div>
    </div>
</div>

<div class="container">
    
    <?php if(isset($_GET['sucesso'])): ?>
        <div class="alert alert-success alert-dismissible fade show no-print border-0 shadow-sm" role="alert">
            <i class="fa-solid fa-circle-check me-2"></i> Operação realizada com sucesso!
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <?php if(isset($_GET['cancelado'])): ?>
        <div class="alert alert-info alert-dismissible fade show no-print border-0 shadow-sm" role="alert">
            <i class="fa-solid fa-circle-info me-2"></i> Solicitação cancelada e removida.
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <?php if($_SESSION['nivel'] == 'admin' && !empty($criticos)): ?>
    <!-- ALERTA COMPACTO DE ESTOQUE CRÍTICO -->
    <div class="alert alert-danger alert-dismissible fade show border-0 shadow-sm mb-3 no-print alerta-estoque-critico-compacto" role="alert">
        <div class="d-flex align-items-center">
            <div class="me-3">
                <i class="fa-solid fa-triangle-exclamation fa-lg icon-vibrate" style="color: #ff6b6b;"></i>
            </div>
            <div class="flex-grow-1">
                <strong class="me-2">Estoque Crítico Detectado!</strong>
                Existem <span class="fw-bold"><?= count($criticos) ?> itens</span> com estoque abaixo de 5 unidades.
                <a href="#estoqueCritico" class="btn btn-sm btn-outline-light ms-2" data-bs-toggle="collapse">
                    <i class="fa-solid fa-chevron-down me-1"></i>Ver Itens
                </a>
                <a href="estoque_critico.php" class="btn btn-sm btn-light text-danger ms-1">
                    <i class="fa-solid fa-file-contract me-1"></i>Relatório Completo
                </a>
            </div>
            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="alert"></button>
        </div>
        
        <!-- Lista condensada dos itens críticos -->
        <div class="collapse mt-2" id="estoqueCritico">
            <div class="row">
                <?php 
                $mostrar_limitado = array_slice($criticos, 0, 8); // Mostra apenas 8 itens
                foreach($mostrar_limitado as $c): 
                    $percentual = min(100, ($c['quantidade_atual'] / 5) * 100);
                ?>
                <div class="col-md-3 col-6 mb-2">
                    <div class="critico-item-compact">
                        <div class="d-flex justify-content-between align-items-center small">
                            <span class="text-truncate" style="max-width: 120px;" title="<?= htmlspecialchars($c['nome']) ?>">
                                <?= htmlspecialchars($c['nome']) ?>
                            </span>
                            <span class="badge bg-danger badge-pulsante"><?= $c['quantidade_atual'] ?></span>
                        </div>
                        <div class="progress progress-micro">
                            <div class="progress-bar progress-bar-critico" 
                                 style="width: <?= $percentual ?>%;"></div>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
                
                <?php if(count($criticos) > 8): ?>
                <div class="col-12 mt-2 text-center">
                    <small class="opacity-75">
                        + <?= count($criticos) - 8 ?> itens críticos. 
                        <a href="estoque_critico.php" class="text-warning fw-bold">Ver todos</a>
                    </small>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <div class="row no-print">
        <?php if($_SESSION['nivel'] == 'admin'): ?>
            <div class="col-md-4">
                <div class="card p-3 shadow-sm h-100">
                    <h6 class="fw-bold text-success mb-3">📥 Entrada de Material</h6>
                    <form method="POST">
                        <input type="text" name="nome_item" class="form-control mb-2" placeholder="Nome do Material" required>
                        <input type="number" name="qtd_item" class="form-control mb-2" placeholder="Quantidade" required min="1">
                        <button type="submit" name="add_item" class="btn btn-success w-100 shadow-sm">Adicionar ao Estoque</button>
                    </form>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card p-3 bg-primary text-white shadow-sm h-100">
                    <h6 class="fw-bold mb-3">📤 Saída Direta (Balcão)</h6>
                    <form method="POST">
                        <!-- INPUT DE BUSCA PARA SAÍDA DIRETA -->
                        <div class="mb-3">
                            <label class="form-label small fw-bold">Item para Saída</label>
                            <div class="searchable-dropdown">
                                <input type="text" class="form-control search-item-input" 
                                       placeholder="🔍 Digite para buscar item..." 
                                       data-target="selectSaidaDireta"
                                       id="inputSaidaDireta"
                                       autocomplete="off">
                                <input type="hidden" name="id_item" id="selectSaidaDireta" value="" required>
                                <div class="dropdown-results" id="resultsSaidaDireta"></div>
                            </div>
                        </div>
                        
                        <select name="id_setor" class="form-select mb-2" required>
                            <option value="">Selecione o Setor...</option>
                            <?php foreach($setores as $s): ?> 
                                <option value="<?= htmlspecialchars($s['id_setor']) ?>"><?= htmlspecialchars($s['nome_setor']) ?></option> 
                            <?php endforeach; ?>
                        </select>
                        <input type="text" name="funcionario" class="form-control mb-2" placeholder="Nome do Responsável" required>
                        <input type="number" name="qtd_saida" class="form-control mb-2" placeholder="Quantidade" required min="1">
                        <button type="submit" name="entrega" class="btn btn-light w-100 text-primary fw-bold" onclick="return confirm('Confirmar saída manual?')">Confirmar Baixa</button>
                    </form>
                </div>
            </div>
            <div class="col-md-4 text-center">
                <div class="card p-3 border-info h-100 shadow-sm">
                    <h6 class="fw-bold text-info mb-3">⚙️ Painel de Controle</h6>
                    <a href="relatorios.php" class="btn btn-primary w-100 mb-2 fw-bold"><i class="fa-solid fa-file-contract me-2"></i>Relatórios de Consumo</a>
                    <button class="btn btn-outline-dark w-100 mb-2" data-bs-toggle="modal" data-bs-target="#modalGerenciarSetores">Gerenciar Setores</button>
                    <a href="unidades.php" class="btn btn-outline-info w-100 mb-2"><i class="fa-solid fa-hospital me-2"></i>Gestão de Unidades</a>
                    <button class="btn btn-outline-info w-100" data-bs-toggle="modal" data-bs-target="#novoUsuario">Gestão de Usuários</button>
                </div>
            </div>
        <?php else: ?>
            <div class="col-md-12 text-center">
                <div class="card p-5 bg-warning border-0 shadow-sm">
                    <h4 class="fw-bold"><i class="fa-solid fa-truck-ramp-box me-2"></i>Central de Requisições</h4>
                    <p class="mb-4">Consulte o estoque abaixo e solicite os materiais necessários para o seu setor.</p>
                    <button class="btn btn-dark btn-lg px-5 shadow" data-bs-toggle="modal" data-bs-target="#modalNovoPedido">FAZER NOVO PEDIDO</button>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <div class="card shadow-sm border-warning mb-4 no-print">
        <div class="card-header bg-warning text-dark fw-bold d-flex justify-content-between align-items-center">
            <span><i class="fa-solid fa-clipboard-list me-2"></i> <?= $_SESSION['nivel'] == 'admin' ? 'Pedidos Aguardando Processamento' : 'Minhas Solicitações Ativas' ?></span>
            <span class="badge bg-dark"><?= $total_pendente ?> pendente(s)</span>
        </div>
        <div class="table-responsive">
            <table class="table table-sm table-hover mb-0 align-middle">
                <thead><tr><th>Data</th><th>Unidade</th><th>Item</th><th>Qtd</th><th>Setor</th><th>Solicitante</th><th>Ações / Status</th></tr></thead>
                <tbody>
                    <?php foreach($pedidos_pendentes as $p): ?>
                    <tr>
                        <td><small><?= date('d/m H:i', strtotime($p['data_pedido'])) ?></small></td>
                        <td>
                            <span class="badge bg-info"><?= htmlspecialchars($p['nome_unidade']) ?></span>
                        </td>
                        <td>
                            <strong><?= htmlspecialchars($p['nome_item']) ?></strong>
                            <?php if(!empty($p['mensagem_solicitante'])): ?>
                                <br><small class="text-muted"><i class="fa-solid fa-comment me-1"></i>Com observação</small>
                            <?php endif; ?>
                        </td>
                        <td><span class="badge bg-light text-dark border"><?= htmlspecialchars($p['quantidade_pedida']) ?></span></td>
                        <td><?= htmlspecialchars($p['nome_setor']) ?></td>
                        <td><small><?= htmlspecialchars($p['nome_solicitante']) ?></small></td>
                        <td>
                            <?php if($_SESSION['nivel'] == 'admin'): ?>
                                <button class="btn btn-sm btn-success py-0" onclick="atenderPedido(<?= $p['id_requisicao'] ?>, '<?= addslashes($p['nome_item']) ?>')">Entregar</button>
                                <button class="btn btn-sm btn-outline-danger py-0 ms-1" onclick="recusarPedido(<?= $p['id_requisicao'] ?>)">Recusar</button>
                                <?php if(!empty($p['mensagem_solicitante'])): ?>
                                    <button class="btn btn-sm btn-outline-info py-0 ms-1" data-bs-toggle="modal" data-bs-target="#modalMensagem<?= $p['id_requisicao'] ?>">
                                        <i class="fa-solid fa-eye"></i>
                                    </button>
                                <?php endif; ?>
                            <?php else: ?>
                                <span class="badge bg-secondary me-2">Pendente</span>
                                <a href="?cancelar_req=<?= htmlspecialchars($p['id_requisicao']) ?>" class="btn btn-sm btn-danger py-0" onclick="return confirm('Deseja cancelar seu pedido?')"><i class="fa-solid fa-trash me-1"></i>Cancelar</a>
                            <?php endif; ?>
                        </td>
                    </tr>
                    
                    <!-- Modal para ver mensagem do solicitante -->
                    <?php if(!empty($p['mensagem_solicitante'])): ?>
                    <div class="modal fade" id="modalMensagem<?= $p['id_requisicao'] ?>" tabindex="-1">
                        <div class="modal-dialog">
                            <div class="modal-content">
                                <div class="modal-header bg-info text-white">
                                    <h6 class="modal-title"><i class="fa-solid fa-comment-dots me-2"></i>Observação do Solicitante</h6>
                                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                                </div>
                                <div class="modal-body">
                                    <div class="mensagem-box mensagem-solicitante">
                                        <strong>Solicitante:</strong> <?= htmlspecialchars($p['nome_solicitante']) ?><br>
                                        <strong>Item:</strong> <?= htmlspecialchars($p['nome_item']) ?> (<?= $p['quantidade_pedida'] ?> un)<br>
                                        <strong>Setor:</strong> <?= htmlspecialchars($p['nome_setor']) ?><br><br>
                                        <strong>Observação:</strong><br>
                                        <?= nl2br(htmlspecialchars($p['mensagem_solicitante'])) ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>
                    
                    <?php endforeach; ?>
                    <?php if(empty($pedidos_pendentes)): ?>
                        <tr><td colspan="7" class="text-center py-4 text-muted">Nenhuma requisição pendente no momento.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Histórico de Requisições -->
    <?php if($_SESSION['nivel'] == 'admin' && isset($requisicoes_finalizadas) && !empty($requisicoes_finalizadas)): ?>
    <div class="card shadow-sm border-info mb-4 no-print">
        <div class="card-header bg-info text-white fw-bold d-flex justify-content-between align-items-center">
            <span><i class="fa-solid fa-history me-2"></i> Histórico de Requisições Finalizadas</span>
        </div>
        <div class="table-responsive">
            <table class="table table-sm table-hover mb-0">
                <thead><tr><th>Data</th><th>Item</th><th>Qtd</th><th>Setor</th><th>Solicitante</th><th>Status</th><th>Mensagens</th></tr></thead>
                <tbody>
                    <?php foreach($requisicoes_finalizadas as $r): ?>
                    <tr>
                        <td><small><?= date('d/m H:i', strtotime($r['data_pedido'])) ?></small></td>
                        <td><strong><?= htmlspecialchars($r['nome_item']) ?></strong></td>
                        <td><span class="badge bg-light text-dark border"><?= htmlspecialchars($r['quantidade_pedida']) ?></span></td>
                        <td><?= htmlspecialchars($r['nome_setor']) ?></td>
                        <td><small><?= htmlspecialchars($r['nome_solicitante']) ?></small></td>
                        <td>
                            <span class="badge bg-<?= $r['status'] == 'atendido' ? 'success' : 'danger' ?>">
                                <?= $r['status'] == 'atendido' ? 'Atendido' : 'Cancelado' ?>
                            </span>
                        </td>
                        <td>
                            <?php if(!empty($r['mensagem_solicitante']) || !empty($r['mensagem_adm'])): ?>
                                <button class="btn btn-sm btn-outline-info py-0" data-bs-toggle="modal" data-bs-target="#modalDetalhes<?= $r['id_requisicao'] ?>">
                                    <i class="fa-solid fa-comments"></i>
                                </button>
                            <?php else: ?>
                                <span class="text-muted small">-</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    
                    <!-- Modal de detalhes -->
                    <div class="modal fade" id="modalDetalhes<?= $r['id_requisicao'] ?>" tabindex="-1">
                        <div class="modal-dialog">
                            <div class="modal-content">
                                <div class="modal-header bg-dark text-white">
                                    <h6 class="modal-title">Detalhes da Requisição #<?= $r['id_requisicao'] ?></h6>
                                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                                </div>
                                <div class="modal-body">
                                    <div class="mb-3">
                                        <strong>Item:</strong> <?= htmlspecialchars($r['nome_item']) ?><br>
                                        <strong>Quantidade:</strong> <?= $r['quantidade_pedida'] ?> un<br>
                                        <strong>Setor:</strong> <?= htmlspecialchars($r['nome_setor']) ?><br>
                                        <strong>Solicitante:</strong> <?= htmlspecialchars($r['nome_solicitante']) ?><br>
                                        <strong>Data:</strong> <?= date('d/m/Y H:i', strtotime($r['data_pedido'])) ?><br>
                                        <strong>Status:</strong> 
                                        <span class="badge bg-<?= $r['status'] == 'atendido' ? 'success' : 'danger' ?>">
                                            <?= $r['status'] == 'atendido' ? 'Atendido' : 'Cancelado' ?>
                                        </span>
                                    </div>
                                    
                                    <?php if(!empty($r['mensagem_solicitante'])): ?>
                                    <div class="mensagem-box mensagem-solicitante mb-2">
                                        <strong><i class="fa-solid fa-user me-1"></i>Observação do Solicitante:</strong><br>
                                        <?= nl2br(htmlspecialchars($r['mensagem_solicitante'])) ?>
                                    </div>
                                    <?php endif; ?>
                                    
                                    <?php if(!empty($r['mensagem_adm'])): ?>
                                    <div class="mensagem-box mensagem-admin">
                                        <strong><i class="fa-solid fa-user-tie me-1"></i>Resposta do Administrador:</strong><br>
                                        <?= nl2br(htmlspecialchars($r['mensagem_adm'])) ?>
                                    </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php elseif($_SESSION['nivel'] == 'operador' && isset($minhas_requisicoes) && !empty($minhas_requisicoes)): ?>
    <div class="card shadow-sm border-info mb-4 no-print">
        <div class="card-header bg-info text-white fw-bold">
            <span><i class="fa-solid fa-history me-2"></i> Minhas Requisições Finalizadas</span>
        </div>
        <div class="table-responsive">
            <table class="table table-sm table-hover mb-0">
                <thead><tr><th>Data</th><th>Item</th><th>Qtd</th><th>Setor</th><th>Status</th><th>Resposta</th></tr></thead>
                <tbody>
                    <?php foreach($minhas_requisicoes as $r): ?>
                    <tr>
                        <td><small><?= date('d/m H:i', strtotime($r['data_pedido'])) ?></small></td>
                        <td><strong><?= htmlspecialchars($r['nome_item']) ?></strong></td>
                        <td><span class="badge bg-light text-dark border"><?= htmlspecialchars($r['quantidade_pedida']) ?></span></td>
                        <td><?= htmlspecialchars($r['nome_setor']) ?></td>
                        <td>
                            <span class="badge bg-<?= $r['status'] == 'atendido' ? 'success' : 'danger' ?>">
                                <?= $r['status'] == 'atendido' ? 'Atendido' : 'Cancelado' ?>
                            </span>
                        </td>
                        <td>
                            <?php if(!empty($r['mensagem_adm'])): ?>
                                <button class="btn btn-sm btn-outline-info py-0" data-bs-toggle="modal" data-bs-target="#modalResposta<?= $r['id_requisicao'] ?>">
                                    <i class="fa-solid fa-eye"></i>
                                </button>
                            <?php else: ?>
                                <span class="text-muted small">-</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    
                    <!-- Modal de resposta -->
                    <div class="modal fade" id="modalResposta<?= $r['id_requisicao'] ?>" tabindex="-1">
                        <div class="modal-dialog">
                            <div class="modal-content">
                                <div class="modal-header bg-dark text-white">
                                    <h6 class="modal-title">Resposta do Administrador</h6>
                                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                                </div>
                                <div class="modal-body">
                                    <div class="mensagem-box mensagem-admin">
                                        <strong>Item:</strong> <?= htmlspecialchars($r['nome_item']) ?><br>
                                        <strong>Status:</strong> 
                                        <span class="badge bg-<?= $r['status'] == 'atendido' ? 'success' : 'danger' ?>">
                                            <?= $r['status'] == 'atendido' ? 'Atendido' : 'Cancelado' ?>
                                        </span><br><br>
                                        <strong>Resposta:</strong><br>
                                        <?= nl2br(htmlspecialchars($r['mensagem_adm'])) ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>

    <div class="card shadow-sm mt-4">
        <div class="card-header bg-white d-flex justify-content-between align-items-center">
            <span class="fw-bold text-secondary">📦 Estoque em Tempo Real 
                <small class="text-muted">(Total: <?= $total_itens ?> itens)</small>
            </span>
            <div class="d-flex align-items-center">
                <input type="text" id="filtroEstoque" onkeyup="filtrarEstoque()" class="form-control form-control-sm me-2 no-print" style="width: 200px;" placeholder="🔍 Buscar material...">
                <?php if($total_paginas_itens > 1): ?>
                <div class="btn-group btn-group-sm">
                    <?php if($pagina_atual > 1): ?>
                        <a href="?pagina_itens=<?= $pagina_atual - 1 ?>" class="btn btn-outline-primary">
                            <i class="fa-solid fa-chevron-left"></i>
                        </a>
                    <?php endif; ?>
                    
                    <button class="btn btn-outline-primary" disabled>
                        Página <?= $pagina_atual ?> de <?= $total_paginas_itens ?>
                    </button>
                    
                    <?php if($pagina_atual < $total_paginas_itens): ?>
                        <a href="?pagina_itens=<?= $pagina_atual + 1 ?>" class="btn btn-outline-primary">
                            <i class="fa-solid fa-chevron-right"></i>
                        </a>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0" id="tabelaEstoque">
                <thead><tr><th>Material</th><th>Quantidade Disponível</th><?php if($_SESSION['nivel'] == 'admin'): ?><th>Ações</th><?php endif; ?></tr></thead>
                <tbody>
                    <?php foreach($itens as $i): ?>
                    <tr class="<?= $i['quantidade_atual'] <= 5 ? 'item-critico-destaque' : '' ?>">
                        <td><strong><?= htmlspecialchars($i['nome']) ?></strong></td>
                        <td>
                            <?php if($i['quantidade_atual'] <= 5): ?>
                                <div class="d-flex align-items-center">
                                    <span class="badge-pulsante badge me-2" style="padding: 5px 8px;">
                                        <i class="fa-solid fa-exclamation"></i>
                                    </span>
                                    <span class="badge bg-danger" style="animation: pulseCritical 1.5s infinite;">
                                        <?= htmlspecialchars($i['quantidade_atual']) ?> un
                                    </span>
                                </div>
                            <?php elseif($i['quantidade_atual'] <= 15): ?>
                                <span class="badge bg-warning text-dark">
                                    <?= htmlspecialchars($i['quantidade_atual']) ?> un
                                </span>
                            <?php else: ?>
                                <span class="badge bg-success">
                                    <?= htmlspecialchars($i['quantidade_atual']) ?> un
                                </span>
                            <?php endif; ?>
                        </td>
                        <?php if($_SESSION['nivel'] == 'admin'): ?>
                        <td class="no-print">
                            <button class="btn btn-sm text-primary" data-bs-toggle="modal" data-bs-target="#editItem<?= htmlspecialchars($i['id_item']) ?>"><i class="fa-solid fa-pen"></i></button>
                            <a href="?desativar=<?= htmlspecialchars($i['id_item']) ?>" class="text-danger ms-2" onclick="return confirm('Esconder item?')"><i class="fa-solid fa-eye-slash"></i></a>
                        </td>
                        <?php endif; ?>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        
        <?php if($total_paginas_itens > 1): ?>
        <div class="card-footer bg-white border-top">
            <nav aria-label="Navegação de páginas">
                <ul class="pagination justify-content-center mb-0">
                    <?php if($pagina_atual > 1): ?>
                        <li class="page-item">
                            <a class="page-link" href="?pagina_itens=1" aria-label="Primeira">
                                <span aria-hidden="true">&laquo;&laquo;</span>
                            </a>
                        </li>
                        <li class="page-item">
                            <a class="page-link" href="?pagina_itens=<?= $pagina_atual - 1 ?>" aria-label="Anterior">
                                <span aria-hidden="true">&laquo;</span>
                            </a>
                        </li>
                    <?php endif; ?>
                    
                    <?php
                    // Mostrar até 5 páginas ao redor da atual
                    $inicio = max(1, $pagina_atual - 2);
                    $fim = min($total_paginas_itens, $pagina_atual + 2);
                    
                    for($i = $inicio; $i <= $fim; $i++): ?>
                        <li class="page-item <?= $i == $pagina_atual ? 'active' : '' ?>">
                            <a class="page-link" href="?pagina_itens=<?= $i ?>"><?= $i ?></a>
                        </li>
                    <?php endfor; ?>
                    
                    <?php if($pagina_atual < $total_paginas_itens): ?>
                        <li class="page-item">
                            <a class="page-link" href="?pagina_itens=<?= $pagina_atual + 1 ?>" aria-label="Próxima">
                                <span aria-hidden="true">&raquo;</span>
                            </a>
                        </li>
                        <li class="page-item">
                            <a class="page-link" href="?pagina_itens=<?= $total_paginas_itens ?>" aria-label="Última">
                                <span aria-hidden="true">&raquo;&raquo;</span>
                            </a>
                        </li>
                    <?php endif; ?>
                </ul>
            </nav>
            <div class="text-center mt-2">
                <small class="text-muted">
                    Mostrando <?= count($itens) ?> de <?= $total_itens ?> itens ativos
                </small>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <?php if($_SESSION['nivel'] == 'admin'): ?>
    <div class="card mt-4 shadow-sm">
        <div class="card-header bg-white d-flex justify-content-between align-items-center">
            <span class="fw-bold text-secondary">📜 Histórico de Movimentações (Ilimitado)</span>
            <input type="text" id="filtroHistorico" onkeyup="filtrarHistorico()" class="form-control form-control-sm w-25 no-print" placeholder="🔍 Filtrar histórico...">
        </div>
        <div class="table-responsive" style="max-height: 500px; overflow-y: auto;">
            <table class="table table-sm table-hover mb-0" id="tabelaHistorico">
                <thead class="table-light sticky-top"><tr><th>Data/Hora</th><th>Unidade</th><th>Item</th><th>Qtd</th><th>Setor</th><th>Pessoa</th><th class="no-print">Ação</th></tr></thead>
                <tbody>
                    <?php foreach($historico as $h): ?>
                    <tr>
                        <td><small><?= date('d/m/Y H:i', strtotime($h['data_entrega'])) ?></small></td>
                        <td>
                            <?php if(!empty($h['nome_unidade'])): ?>
                                <span class="badge bg-info"><?= htmlspecialchars($h['nome_unidade']) ?></span>
                            <?php else: ?>
                                <span class="badge bg-secondary">Sem unidade</span>
                            <?php endif; ?>
                        </td>
                        <td><?= htmlspecialchars($h['nome_item']) ?></td>
                        <td><?= htmlspecialchars($h['quantidade_entregue']) ?></td>
                        <td><?= htmlspecialchars($h['nome_setor']) ?></td>
                        <td><?= htmlspecialchars($h['funcionario']) ?></td>
                        <td class="no-print">
                            <a href="?del_hist=<?= htmlspecialchars($h['id_entrega']) ?>" class="text-danger" onclick="return confirm('Apagar registro definitivamente?')"><i class="fa-solid fa-trash-can"></i></a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- =============== MODIFICAÇÃO 4: TABELA DE CONTROLE DE ACESSOS COM UNIDADE =============== -->
    <div class="card mt-4 border-primary shadow-sm">
        <div class="card-header bg-primary text-white fw-bold"><i class="fa-solid fa-users-gear me-2"></i>Controle de Acessos</div>
        <div class="table-responsive">
            <table class="table align-middle mb-0">
                <thead><tr><th>Nome Completo</th><th>Login</th><th>Nível</th><th>Unidade</th><th>Ações</th></tr></thead>
                <tbody>
                    <?php foreach($usuarios_lista as $u): ?>
                    <tr>
                        <td><?= htmlspecialchars($u['nome']) ?></td>
                        <td><code><?= htmlspecialchars($u['usuario']) ?></code></td>
                        <td><span class="badge bg-light text-dark border"><?= ucfirst(htmlspecialchars($u['nivel'])) ?></span></td>
                        <td>
                            <?php if($u['nome_unidade']): ?>
                                <span class="badge bg-info"><?= htmlspecialchars($u['nome_unidade']) ?></span>
                            <?php else: ?>
                                <span class="badge bg-secondary">Sem unidade</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <button class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#editU<?= htmlspecialchars($u['id_usuario']) ?>"><i class="fa-solid fa-user-pen"></i></button>
                            <?php if($u['id_usuario'] != $_SESSION['user_id']): ?>
                                <a href="?del_u=<?= htmlspecialchars($u['id_usuario']) ?>" class="btn btn-sm btn-outline-danger ms-1" onclick="return confirm('Excluir usuário permanentemente?')"><i class="fa-solid fa-user-xmark"></i></a>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>
</div>

<!-- Modal para novo pedido COM MENSAGEM E UNIDADE -->
<div class="modal fade" id="modalNovoPedido" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content border-0 shadow-lg">
            <form method="POST">
                <div class="modal-header bg-warning text-dark">
                    <h5><i class="fa-solid fa-paper-plane me-2"></i>Solicitar Material</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <?php 
                    // Buscar unidade do usuário
                    $stmt_unidade = $pdo->prepare("SELECT u.id_unidade, un.nome_unidade 
                                                   FROM usuarios u 
                                                   JOIN unidades un ON u.id_unidade = un.id_unidade 
                                                   WHERE u.id_usuario = ?");
                    $stmt_unidade->execute([$_SESSION['user_id']]);
                    $minha_unidade = $stmt_unidade->fetch();
                    ?>
                    
                    <!-- Unidade (somente admin pode escolher, operador usa a sua) -->
                    <?php if($_SESSION['nivel'] == 'admin'): ?>
                        <label class="small fw-bold">Unidade Solicitante</label>
                        <select name="id_unidade_req" class="form-select mb-3" required>
                            <option value="">Selecione a Unidade...</option>
                            <?php 
                            $unidades_ativas = $pdo->query("SELECT * FROM unidades WHERE ativo = 1 ORDER BY nome_unidade ASC")->fetchAll();
                            foreach($unidades_ativas as $uni): ?>
                                <option value="<?= htmlspecialchars($uni['id_unidade']) ?>" <?= $minha_unidade['id_unidade'] == $uni['id_unidade'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($uni['nome_unidade']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    <?php else: ?>
                        <input type="hidden" name="id_unidade_req" value="<?= $minha_unidade['id_unidade'] ?>">
                        <div class="alert alert-info py-2 small mb-3">
                            <i class="fa-solid fa-hospital me-1"></i> 
                            <strong>Unidade:</strong> <?= htmlspecialchars($minha_unidade['nome_unidade']) ?>
                        </div>
                    <?php endif; ?>
                    
                    <!-- INPUT DE BUSCA PARA REQUISIÇÃO -->
                    <label class="small fw-bold">Selecione o Material</label>
                    <div class="searchable-dropdown">
                        <input type="text" class="form-control search-item-input" 
                               placeholder="🔍 Digite para buscar material..." 
                               data-target="selectRequisicao"
                               id="inputRequisicao"
                               autocomplete="off">
                        <input type="hidden" name="id_item_req" id="selectRequisicao" value="" required>
                        <div class="dropdown-results" id="resultsRequisicao"></div>
                    </div>
                    
                    <label class="small fw-bold mt-3">Setor Destino</label>
                    <select name="id_setor_req" class="form-select mb-3" required>
                        <option value="">Para qual setor é o pedido?</option>
                        <?php foreach($setores as $s): ?>
                            <option value="<?= htmlspecialchars($s['id_setor']) ?>"><?= htmlspecialchars($s['nome_setor']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    
                    <label class="small fw-bold">Quantidade Desejada</label>
                    <input type="number" name="qtd_req" class="form-control mb-3" min="1" placeholder="Ex: 5" required>
                    
                    <label class="small fw-bold">
                        <i class="fa-solid fa-comment-dots me-1"></i>Observação (Opcional)
                    </label>
                    <textarea name="msg_solicitante" class="form-control" rows="2" placeholder="Informe alguma observação importante sobre esta requisição..."></textarea>
                    <small class="text-muted">Ex: "Urgente para cirurgia", "Material específico da marca X", etc.</small>
                </div>
                <div class="modal-footer">
                    <button type="submit" name="fazer_pedido" class="btn btn-warning w-100 fw-bold shadow-sm" id="btnEnviarRequisicao" disabled>
                        ENVIAR PARA O ALMOXARIFADO
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php if($precisa_configurar): ?>
<div class="modal fade" id="modalSegurancaObrigatorio" data-bs-backdrop="static" data-bs-keyboard="false" tabindex="-1">
    <div class="modal-dialog"><div class="modal-content border-danger"><div class="modal-header bg-danger text-white"><h5>Segurança do Usuário</h5></div><div class="modal-body">
        <p>Para sua segurança, configure uma pergunta de recuperação.</p>
        <form method="POST">
            <select name="p_sec" class="form-select mb-3" required>
                <option value="Qual o nome do seu primeiro animal de estimação?">Qual o nome do seu primeiro animal?</option>
                <option value="Qual a cidade onde você nasceu?">Qual a cidade onde você nasceu?</option>
                <option value="Qual o nome da sua mãe?">Qual o nome da sua mãe?</option>
            </select>
            <input type="text" name="r_sec" class="form-control mb-3" placeholder="Sua Resposta Secreta" required>
            <button type="submit" name="configurar_seguranca" class="btn btn-danger w-100 fw-bold shadow">SALVAR CONFIGURAÇÃO</button>
        </form>
    </div></div></div>
</div>
<script>document.addEventListener("DOMContentLoaded", function() { new bootstrap.Modal(document.getElementById('modalSegurancaObrigatorio')).show(); });</script>
<?php endif; ?>

<div class="modal fade" id="modalGerenciarSetores" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header"><h5><i class="fa-solid fa-building-user me-2"></i>Setores Cadastrados</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
            <div class="modal-body">
                <form method="POST" class="mb-4">
                    <div class="input-group">
                        <input type="text" name="nome_setor" class="form-control" placeholder="Novo Setor" required>
                        <button type="submit" name="add_setor" class="btn btn-dark">Adicionar</button>
                    </div>
                </form>
                <ul class="list-group">
                    <?php foreach($setores as $s): ?>
                        <li class="list-group-item d-flex justify-content-between align-items-center">
                            <?= htmlspecialchars($s['nome_setor']) ?>
                            <div>
                                <button class="btn btn-sm text-primary me-2" data-bs-toggle="modal" data-bs-target="#editSetor<?= htmlspecialchars($s['id_setor']) ?>"><i class="fa-solid fa-pen"></i></button>
                                <a href="?del_setor=<?= htmlspecialchars($s['id_setor']) ?>" class="text-danger" onclick="return confirm('Remover setor definitivamente?')"><i class="fa-solid fa-circle-xmark"></i></a>
                            </div>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </div>
    </div>
</div>

<?php foreach($setores as $s): ?>
<div class="modal fade" id="editSetor<?= $s['id_setor'] ?>" tabindex="-1" style="z-index: 1060;">
    <div class="modal-dialog modal-sm">
        <div class="modal-content border-primary">
            <form method="POST">
                <div class="modal-body p-4 text-center">
                    <h6 class="fw-bold mb-3 text-primary">Renomear Setor</h6>
                    <input type="hidden" name="id_setor_edit" value="<?= htmlspecialchars($s['id_setor']) ?>">
                    <input type="text" name="nome_setor_edit" value="<?= htmlspecialchars($s['nome_setor']) ?>" class="form-control mb-3 text-center" required>
                    <button type="submit" name="editar_setor" class="btn btn-primary w-100">Atualizar</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endforeach; ?>

<?php if($_SESSION['nivel'] == 'admin'): ?>
    <!-- =============== MODIFICAÇÃO 5: MODAL DE NOVO USUÁRIO COM UNIDADE =============== -->
    <div class="modal fade" id="novoUsuario" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content border-0 shadow">
                <form method="POST">
                    <div class="modal-header bg-primary text-white">
                        <h5><i class="fa-solid fa-user-plus me-2"></i>Novo Usuário</h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <input type="text" name="u_nome" class="form-control mb-3" placeholder="Nome Completo" required>
                        <input type="text" name="u_user" class="form-control mb-3" placeholder="Nome de Login" required>
                        <input type="password" id="cad_pass" name="u_pass" class="form-control mb-3" placeholder="Senha Forte" required>
                        <div id="cad_msg" class="mb-3"></div>
                        
                        <div class="row mb-3">
                            <div class="col-md-6">
                                <label class="small fw-bold mb-1">Nível de Acesso</label>
                                <select name="u_nivel" class="form-select">
                                    <option value="operador">Operador</option>
                                    <option value="admin">Administrador</option>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="small fw-bold mb-1">Unidade</label>
                                <select name="u_unidade" class="form-select" required>
                                    <option value="">Selecione...</option>
                                    <?php 
                                    // Buscar unidades ativas
                                    $unidades_ativas = $pdo->query("SELECT * FROM unidades WHERE ativo = 1 ORDER BY nome_unidade ASC")->fetchAll();
                                    foreach($unidades_ativas as $uni): ?>
                                        <option value="<?= htmlspecialchars($uni['id_unidade']) ?>">
                                            <?= htmlspecialchars($uni['nome_unidade']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        
                        <input type="text" name="u_pergunta" class="form-control mb-3" placeholder="Pergunta de Segurança" required>
                        <input type="text" name="u_resposta" class="form-control mb-3" placeholder="Resposta da Pergunta" required>
                    </div>
                    <div class="modal-footer">
                        <button type="submit" id="cad_btn" name="add_usuario" class="btn btn-primary w-100 shadow" disabled>
                            <i class="fa-solid fa-user-plus me-2"></i>Cadastrar Usuário
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- =============== MODIFICAÇÃO 6: MODAL DE EDIÇÃO DE USUÁRIO COM UNIDADE =============== -->
    <?php foreach($usuarios_lista as $u): ?>
        <div class="modal fade" id="editU<?= $u['id_usuario'] ?>" tabindex="-1">
            <div class="modal-dialog">
                <div class="modal-content border-0 shadow">
                    <form method="POST">
                        <div class="modal-header bg-dark text-white">
                            <h5>Editar Usuário: <?= htmlspecialchars($u['nome']) ?></h5>
                            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                        </div>
                        <div class="modal-body">
                            <input type="hidden" name="id_u_edit" value="<?= htmlspecialchars($u['id_usuario']) ?>">
                            
                            <div class="mb-3">
                                <label class="small fw-bold">Nome Completo</label>
                                <input type="text" name="u_nome_edit" value="<?= htmlspecialchars($u['nome']) ?>" class="form-control" required>
                            </div>
                            
                            <div class="row mb-3">
                                <div class="col-md-6">
                                    <label class="small fw-bold">Nível de Acesso</label>
                                    <select name="u_nivel_edit" class="form-select">
                                        <option value="operador" <?= $u['nivel'] == 'operador' ? 'selected' : '' ?>>Operador</option>
                                        <option value="admin" <?= $u['nivel'] == 'admin' ? 'selected' : '' ?>>Administrador</option>
                                    </select>
                                </div>
                                <div class="col-md-6">
                                    <label class="small fw-bold">Unidade</label>
                                    <select name="u_unidade_edit" class="form-select" required>
                                        <option value="">Selecione...</option>
                                        <?php 
                                        $unidades_ativas = $pdo->query("SELECT * FROM unidades WHERE ativo = 1 ORDER BY nome_unidade ASC")->fetchAll();
                                        foreach($unidades_ativas as $uni): ?>
                                            <option value="<?= htmlspecialchars($uni['id_unidade']) ?>" 
                                                <?= $u['id_unidade'] == $uni['id_unidade'] ? 'selected' : '' ?>>
                                                <?= htmlspecialchars($uni['nome_unidade']) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                            
                            <div class="mb-3">
                                <label class="small fw-bold text-danger">
                                    <i class="fa-solid fa-key me-1"></i>Nova Senha
                                </label>
                                <input type="password" name="u_pass_edit" class="form-control" placeholder="Deixe em branco para manter a atual">
                                <small class="text-muted">Preencha apenas se quiser alterar a senha</small>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="submit" name="edit_usuario" class="btn btn-primary w-100 shadow">
                                <i class="fa-solid fa-floppy-disk me-2"></i>Salvar Alterações
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    <?php endforeach; ?>
<?php endif; ?>

<?php foreach($itens as $i): ?>
    <div class="modal fade" id="editItem<?= $i['id_item'] ?>" tabindex="-1"><div class="modal-dialog"><div class="modal-content p-4 border-0 shadow"><form method="POST">
        <h5 class="fw-bold mb-3 text-primary"><i class="fa-solid fa-box-open me-2"></i>Editar Material</h5>
        <input type="hidden" name="id_item_edit" value="<?= htmlspecialchars($i['id_item']) ?>">
        <label class="small fw-bold">Nome do Material</label>
        <input type="text" name="nome_edit" value="<?= htmlspecialchars($i['nome']) ?>" class="form-control mb-2">
        <label class="small fw-bold">Quantidade Física em Estoque</label>
        <input type="number" name="qtd_edit" value="<?= htmlspecialchars($i['quantidade_atual']) ?>" class="form-control mb-3">
        <button type="submit" name="salvar_edicao_item" class="btn btn-primary w-100 shadow">Atualizar Item</button>
    </form></div></div></div>
<?php endforeach; ?>

<!-- Botão flutuante para busca global -->
<button class="btn btn-busca-global no-print" onclick="abrirBuscaGlobal()" title="Buscar item em todo o estoque">
    <i class="fa-solid fa-search"></i>
</button>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
// Array global para armazenar todos os itens
let todosItens = [];
let carregandoItens = false;

// Função para carregar todos os itens via AJAX
function carregarTodosItens() {
    if (carregandoItens) return;
    
    carregandoItens = true;
    fetch('ajax_carregar_itens.php')
        .then(response => response.json())
        .then(data => {
            todosItens = data;
            console.log('Itens carregados:', todosItens.length);
            carregandoItens = false;
        })
        .catch(error => {
            console.error('Erro ao carregar itens:', error);
            carregandoItens = false;
        });
}

// Função para buscar itens em tempo real
function buscarItens(termo, targetId, resultsId, inputElement) {
    if (termo.length < 2) {
        document.getElementById(resultsId).style.display = 'none';
        return;
    }
    
    // Primeiro, tentar usar os itens já carregados
    if (todosItens.length > 0) {
        exibirResultadosBusca(todosItens, termo, targetId, resultsId, inputElement);
    } else {
        // Se não tiver carregado, buscar via AJAX
        fetch(`ajax_buscar_item.php?termo=${encodeURIComponent(termo)}`)
            .then(response => response.json())
            .then(data => {
                exibirResultadosBusca(data, termo, targetId, resultsId, inputElement);
            })
            .catch(error => console.error('Erro na busca:', error));
    }
}

function exibirResultadosBusca(itens, termo, targetId, resultsId, inputElement) {
    const termoLower = termo.toLowerCase();
    const resultados = itens.filter(item => 
        item.nome.toLowerCase().includes(termoLower) ||
        item.nome.toLowerCase().replace(/\s/g, '').includes(termoLower.replace(/\s/g, ''))
    ).slice(0, 20); // Limitar a 20 resultados
    
    const resultsDiv = document.getElementById(resultsId);
    resultsDiv.innerHTML = '';
    
    if (resultados.length > 0) {
        resultados.forEach(item => {
            const div = document.createElement('div');
            div.className = 'dropdown-item-result';
            div.innerHTML = `
                <strong>${highlightText(item.nome, termo)}</strong> 
                <span class="badge ${item.quantidade_atual <= 5 ? 'bg-danger' : item.quantidade_atual <= 15 ? 'bg-warning text-dark' : 'bg-success'} float-end">
                    ${item.quantidade_atual} un
                </span>
                <br>
                <small class="text-muted">Código: ${item.id_item}</small>
            `;
            div.onclick = function() {
                document.getElementById(targetId).value = item.id_item;
                inputElement.value = item.nome;
                resultsDiv.style.display = 'none';
                
                // Habilitar botão de envio se for requisição
                if (targetId === 'selectRequisicao') {
                    document.getElementById('btnEnviarRequisicao').disabled = false;
                }
            };
            resultsDiv.appendChild(div);
        });
        resultsDiv.style.display = 'block';
    } else {
        resultsDiv.innerHTML = '<div class="p-3 text-center text-muted">Nenhum item encontrado</div>';
        resultsDiv.style.display = 'block';
    }
}

// Função para destacar texto na busca
function highlightText(text, searchTerm) {
    if (!searchTerm) return text;
    
    const regex = new RegExp(`(${searchTerm.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')})`, 'gi');
    return text.replace(regex, '<span class="search-highlight">$1</span>');
}

// Configurar busca em tempo real para todos os inputs
document.addEventListener('DOMContentLoaded', function() {
    // Carregar itens quando a página carregar
    carregarTodosItens();
    
    // Configurar inputs de busca
    document.querySelectorAll('.search-item-input').forEach(input => {
        input.addEventListener('input', function() {
            const targetId = this.getAttribute('data-target');
            const searchTerm = this.value.trim();
            const resultadosId = 'results' + targetId.replace('select', '');
            
            buscarItens(searchTerm, targetId, resultadosId, this);
        });
        
        // Esconder resultados ao clicar fora
        input.addEventListener('blur', function() {
            setTimeout(() => {
                const targetId = this.getAttribute('data-target');
                const resultadosId = 'results' + targetId.replace('select', '');
                document.getElementById(resultadosId).style.display = 'none';
            }, 200);
        });
        
        // Mostrar resultados ao focar novamente
        input.addEventListener('focus', function() {
            const targetId = this.getAttribute('data-target');
            const searchTerm = this.value.trim();
            const resultadosId = 'results' + targetId.replace('select', '');
            
            if (searchTerm.length >= 2) {
                buscarItens(searchTerm, targetId, resultadosId, this);
            }
        });
    });
});

// Funções para busca global
function abrirBuscaGlobal() {
    const modal = new bootstrap.Modal(document.getElementById('modalBuscaItem'));
    modal.show();
    
    // Limpar resultados anteriores
    document.getElementById('resultadosBuscaGlobal').innerHTML = `
        <tr>
            <td colspan="4" class="text-center py-4 text-muted">
                <i class="fa-solid fa-search fa-lg mb-2"></i><br>
                Digite acima para buscar itens
            </td>
        </tr>
    `;
    
    // Focar no input
    setTimeout(() => {
        document.getElementById('buscaGlobalItem').focus();
    }, 500);
}

// Configurar busca global
document.getElementById('buscaGlobalItem')?.addEventListener('input', function() {
    buscarItemGlobal(this.value);
});

function buscarItemGlobal(termo) {
    if (termo.length < 2) {
        document.getElementById('resultadosBuscaGlobal').innerHTML = `
            <tr>
                <td colspan="4" class="text-center py-4 text-muted">
                    <i class="fa-solid fa-search fa-lg mb-2"></i><br>
                    Digite pelo menos 2 caracteres
                </td>
            </tr>
        `;
        return;
    }
    
    // Usar itens já carregados ou buscar via AJAX
    if (todosItens.length > 0) {
        exibirResultadosBuscaGlobal(todosItens, termo);
    } else {
        fetch(`ajax_buscar_item.php?termo=${encodeURIComponent(termo)}`)
            .then(response => response.json())
            .then(data => {
                exibirResultadosBuscaGlobal(data, termo);
            })
            .catch(error => {
                console.error('Erro na busca global:', error);
                document.getElementById('resultadosBuscaGlobal').innerHTML = `
                    <tr>
                        <td colspan="4" class="text-center py-4 text-danger">
                            <i class="fa-solid fa-exclamation-triangle me-2"></i>
                            Erro ao buscar itens
                        </td>
                    </tr>
                `;
            });
    }
}

function exibirResultadosBuscaGlobal(itens, termo) {
    const termoLower = termo.toLowerCase();
    const resultados = itens.filter(item => 
        item.nome.toLowerCase().includes(termoLower)
    );
    
    const tbody = document.getElementById('resultadosBuscaGlobal');
    tbody.innerHTML = '';
    
    if (resultados.length === 0) {
        tbody.innerHTML = `
            <tr>
                <td colspan="4" class="text-center py-4 text-muted">
                    <i class="fa-solid fa-search fa-lg mb-2"></i><br>
                    Nenhum item encontrado
                </td>
            </tr>
        `;
        return;
    }
    
    resultados.forEach(item => {
        const tr = document.createElement('tr');
        tr.innerHTML = `
            <td>
                <strong>${highlightText(item.nome, termo)}</strong>
                <br><small class="text-muted">Código: ${item.id_item}</small>
            </td>
            <td class="text-center">
                <span class="badge ${item.quantidade_atual <= 5 ? 'bg-danger' : item.quantidade_atual <= 15 ? 'bg-warning text-dark' : 'bg-success'}">
                    ${item.quantidade_atual} un
                </span>
            </td>
            <td class="text-center">
                ${item.quantidade_atual <= 5 ? 
                    '<span class="badge bg-danger">CRÍTICO</span>' : 
                    item.quantidade_atual <= 15 ? 
                    '<span class="badge bg-warning">BAIXO</span>' : 
                    '<span class="badge bg-success">NORMAL</span>'
                }
            </td>
            <td class="text-center">
                <button class="btn btn-sm btn-outline-primary" onclick="usarItemParaSaida(${item.id_item}, '${item.nome.replace(/'/g, "\\'")}', ${item.quantidade_atual})">
                    <i class="fa-solid fa-hand-holding-medical"></i> Usar
                </button>
            </td>
        `;
        tbody.appendChild(tr);
    });
}

function usarItemParaSaida(id, nome, estoque) {
    // Preencher o campo de saída direta se estiver visível
    const saidaInput = document.getElementById('inputSaidaDireta');
    if (saidaInput) {
        document.getElementById('selectSaidaDireta').value = id;
        saidaInput.value = nome;
        
        // Fechar modal se estiver aberto
        const modal = bootstrap.Modal.getInstance(document.getElementById('modalBuscaItem'));
        if (modal) modal.hide();
        
        // Rolar até o formulário de saída direta
        saidaInput.scrollIntoView({ behavior: 'smooth' });
    }
    
    // Preencher o campo de requisição se o modal estiver aberto
    const requisicaoInput = document.getElementById('inputRequisicao');
    if (requisicaoInput && document.querySelector('#modalNovoPedido').classList.contains('show')) {
        document.getElementById('selectRequisicao').value = id;
        requisicaoInput.value = nome;
        document.getElementById('btnEnviarRequisicao').disabled = false;
        
        // Fechar modal de busca global
        const modalBusca = bootstrap.Modal.getInstance(document.getElementById('modalBuscaItem'));
        if (modalBusca) modalBusca.hide();
    }
}

// Modificar a função de filtro da tabela de estoque
function filtrarEstoque() {
    let input = document.getElementById("filtroEstoque").value.toUpperCase();
    let rows = document.querySelector("#tabelaEstoque tbody").rows;
    
    let encontrouNaPagina = false;
    for (let row of rows) { 
        const match = row.cells[0].innerText.toUpperCase().includes(input);
        row.style.display = match ? "" : "none";
        if (match) encontrouNaPagina = true;
    }
    
    // Se não encontrou na página atual e tem pelo menos 2 caracteres, buscar globalmente
    if (!encontrouNaPagina && input.length >= 2) {
        buscarItemGlobalParaEstoque(input);
    } else if (input.length === 0) {
        // Se limpou a busca, mostrar todos os itens da página
        for (let row of rows) { 
            row.style.display = "";
        }
        
        // Remover alerta se existir
        const alertaExistente = document.querySelector('.alerta-item-outra-pagina');
        if (alertaExistente) {
            alertaExistente.remove();
        }
    }
}

function buscarItemGlobalParaEstoque(termo) {
    if (todosItens.length > 0) {
        const resultados = todosItens.filter(item => 
            item.nome.toUpperCase().includes(termo.toUpperCase())
        );
        
        if (resultados.length > 0) {
            mostrarAvisoItemOutraPagina(resultados[0]);
        }
    } else {
        fetch(`ajax_buscar_item.php?termo=${encodeURIComponent(termo)}`)
            .then(response => response.json())
            .then(data => {
                if (data.length > 0) {
                    mostrarAvisoItemOutraPagina(data[0]);
                }
            });
    }
}

function mostrarAvisoItemOutraPagina(item) {
    // Remover alerta anterior se existir
    const alertaAnterior = document.querySelector('.alerta-item-outra-pagina');
    if (alertaAnterior) {
        alertaAnterior.remove();
    }
    
    const alertDiv = document.createElement('div');
    alertDiv.className = 'alert alert-info alert-dismissible fade show mt-2 alerta-item-outra-pagina';
    alertDiv.innerHTML = `
        <i class="fa-solid fa-info-circle me-2"></i>
        O item "<strong>${item.nome}</strong>" foi encontrado no estoque (${item.quantidade_atual} unidades), 
        mas está em outra página. 
        <a href="javascript:void(0)" onclick="irParaItem(${item.id_item})" class="alert-link">Clique aqui para ver</a>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    `;
    
    // Inserir após o card header
    const cardHeader = document.querySelector('#tabelaEstoque').closest('.card').querySelector('.card-header');
    if (cardHeader) {
        cardHeader.parentNode.insertBefore(alertDiv, cardHeader.nextElementSibling);
    }
}

function irParaItem(idItem) {
    // Redirecionar para a página onde o item está (buscar via AJAX)
    fetch(`ajax_encontrar_pagina.php?id_item=${idItem}`)
        .then(response => response.json())
        .then(data => {
            if (data.pagina) {
                window.location.href = `?pagina_itens=${data.pagina}#item-${idItem}`;
            } else {
                // Se não encontrar a página, usar busca global
                abrirBuscaGlobal();
                document.getElementById('buscaGlobalItem').value = '';
                document.getElementById('buscaGlobalItem').focus();
            }
        })
        .catch(error => {
            console.error('Erro ao encontrar página:', error);
            abrirBuscaGlobal();
        });
}

// Restante do código JavaScript existente...
const cadPass = document.getElementById('cad_pass');
const cadBtn = document.getElementById('cad_btn');
const cadMsg = document.getElementById('cad_msg');

if(cadPass){ 
    cadPass.addEventListener('input', () => {
        const regex = /^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[@$!%*?&])[A-Za-z\d@$!%*?&]{8,}$/;
        if(regex.test(cadPass.value)){ 
            cadMsg.innerHTML = '<small class="text-success fw-bold"><i class="fa-solid fa-check"></i> Senha Forte!</small>'; 
            cadBtn.disabled = false; 
        } else { 
            cadMsg.innerHTML = '<small class="text-danger">Requisito senha forte não atingido.</small>'; 
            cadBtn.disabled = true; 
        }
    });
}

function filtrarHistorico() {
    let input = document.getElementById("filtroHistorico").value.toUpperCase();
    let rows = document.querySelector("#tabelaHistorico tbody").rows;
    for (let row of rows) { 
        row.style.display = row.innerText.toUpperCase().includes(input) ? "" : "none"; 
    }
}

// Função para atender pedido com mensagem
function atenderPedido(id, itemNome) {
    const resposta = prompt('Atender pedido de "' + itemNome + '"\n\nDigite uma mensagem para o solicitante (opcional):\nEx: "Material entregue", "Entregue na recepção", etc.');
    
    if (resposta !== null) {
        // Se o usuário cancelar, resposta será null
        const url = '?atender_req=' + id + (resposta.trim() !== '' ? '&resp=' + encodeURIComponent(resposta) : '');
        if (confirm('Confirmar atendimento deste pedido?')) {
            window.location.href = url;
        }
    }
}

// Função para recusar pedido com mensagem
function recusarPedido(id) {
    const motivo = prompt('Recusar solicitação\n\nInforme o motivo da recusa (opcional):\nEx: "Estoque insuficiente", "Item indisponível", etc.');
    
    if (motivo !== null) {
        // Se o usuário cancelar, motivo será null
        const url = '?cancelar_req=' + id + (motivo.trim() !== '' ? '&resp=' + encodeURIComponent(motivo) : '');
        if (confirm('Confirmar recusa desta solicitação?')) {
            window.location.href = url;
        }
    }
}

// Som de alerta opcional (suave)
function tocarAlertaEstoque() {
    try {
        const audioContext = new (window.AudioContext || window.webkitAudioContext)();
        const oscillator = audioContext.createOscillator();
        const gainNode = audioContext.createGain();
        
        oscillator.connect(gainNode);
        gainNode.connect(audioContext.destination);
        
        oscillator.frequency.value = 523.25; // Nota C5
        oscillator.type = 'sine';
        
        gainNode.gain.setValueAtTime(0.1, audioContext.currentTime);
        gainNode.gain.exponentialRampToValueAtTime(0.01, audioContext.currentTime + 0.5);
        
        oscillator.start(audioContext.currentTime);
        oscillator.stop(audioContext.currentTime + 0.5);
    } catch(e) {
        console.log('Áudio não suportado ou bloqueado pelo navegador');
    }
}

// Tocar alerta quando a página carrega com itens críticos
<?php if(!empty($criticos)): ?>
setTimeout(() => {
    tocarAlertaEstoque();
}, 1000);
<?php endif; ?>

// Monitor de inatividade via Fetch (Verifica o servidor a cada 30s)
setInterval(function() {
    fetch('verificar_sessao.php')
        .then(response => response.text())
        .then(status => {
            if (status.trim() === "expirado") {
                window.location.href = "login.php?erro=inatividade";
            }
        });
}, 30000); 

// Reinicia o contador no servidor sempre que o usuário interagir
function resetarSessao() {
    fetch('verificar_sessao.php?ping=1');
}

// Eventos que indicam que o usuário está ativo
window.onmousemove = resetarSessao;
window.onkeypress = resetarSessao;
window.onclick = resetarSessao;
</script>
</body>
</html>