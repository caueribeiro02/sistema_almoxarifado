-- Adicionar coluna de unidade se não existir
ALTER TABLE usuarios ADD COLUMN IF NOT EXISTS id_unidade INT NULL AFTER nivel;

-- Atualizar usuários existentes para uma unidade padrão (ex: primeira unidade ativa)
UPDATE usuarios u 
SET id_unidade = (SELECT id_unidade FROM unidades WHERE ativo = 1 LIMIT 1) 
WHERE u.id_unidade IS NULL;

-- Adicionar chave estrangeira
ALTER TABLE usuarios 
ADD CONSTRAINT fk_usuario_unidade 
FOREIGN KEY (id_unidade) 
REFERENCES unidades(id_unidade) 
ON DELETE SET NULL;