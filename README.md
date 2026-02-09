# 🏥 Sistema de Almoxarifado Saúde

[![PHP 8.1+](https://img.shields.io/badge/PHP-8.1%2B-777BB4?style=for-the-badge&logo=php&logoColor=white)](https://www.php.net/)
[![MySQL 8.0+](https://img.shields.io/badge/MySQL-8.0%2B-4479A1?style=for-the-badge&logo=mysql&logoColor=white)](https://www.mysql.com/)
[![Bootstrap 5.3](https://img.shields.io/badge/Bootstrap-5.3-7952B3?style=for-the-badge&logo=bootstrap&logoColor=white)](https://getbootstrap.com/)
[![Chart.js](https://img.shields.io/badge/Chart.js-4.0%2B-FF6384?style=for-the-badge&logo=chartdotjs&logoColor=white)](https://www.chartjs.org/)
[![Font Awesome 6.4](https://img.shields.io/badge/Font_Awesome-6.4-528DD7?style=for-the-badge&logo=fontawesome&logoColor=white)](https://fontawesome.com/)
[![License MIT](https://img.shields.io/badge/License-MIT-blue.svg?style=for-the-badge)](LICENSE)

Sistema completo para gestão de estoque hospitalar com **controle multi-unidades, requisições online e relatórios em tempo real**.

# 🚀 Comece Rápido
---
## 📦 Instalação em 5 minutos

```bash
# 1. Clone o repositório
git clone https://github.com/seuusuario/almoxarifado-saude.git
cd almoxarifado-saude

# 2. Configure o banco (MySQL/MariaDB)
mysql -u root -p < database.sql

# 3. Ajuste as credenciais
nano config/db.php

# 4. Acesse o sistema
# URL: http://localhost/almoxarifado-saude/
# Login padrão: admin / admin123
```
---
# 🛠️ Tecnologias

- Backend: PHP 8.1+, PDO, MySQL

- Frontend: Bootstrap 5.3, Chart.js, Font Awesome

- Segurança: Password Hash, Prepared Statements, Anti-brute force

- Recursos: AJAX, Session timeout, Exportação CSV
---
# 🔐 Segurança Implementada
### ✅ Proteção contra SQL Injection (Prepared Statements)
### ✅ Hash de senhas (password_hash)
### ✅ Timeout automático (10 minutos inatividade)
### ✅ Limite de tentativas (5 por usuário)
### ✅ XSS Protection (htmlspecialchars)
### ✅ Logs de auditoria (Todas as ações)
### ✅ Controle por níveis (Admin/Operador)
---
# 📊 Dashboard
### **Para Administradores:**
```text
📥 Entrada de Material   📤 Saída Direta   ⚙️ Controle
📋 Requisições Pendentes 📦 Estoque Real   📜 Histórico
👥 Gestão de Usuários    🏥 Unidades       📊 Relatórios
```
### **Para Operadores:**
```text
🔍 Consultar Estoque    📝 Fazer Pedido    ⏳ Meus Pedidos
📊 Histórico Pessoal   🔔 Notificações    👤 Meu Perfil
```
---
# ⚡ Recursos Avançados
---
## 🔍 Busca em Tempo Real
```javascript
// Digite para buscar itens instantaneamente
// Resultados após 2 caracteres
// Highlight dos termos encontrados
```
## 🚨 Alertas Inteligentes
```php
// Alerta visual e sonoro para:
// - Estoque ≤ 5 unidades (CRÍTICO)
// - Estoque ≤ 15 unidades (ATENÇÃO)
// - Previsão de dias restantes
```
## 📈 Relatórios Interativos
- Gráficos de consumo (Chart.js)

- Filtros por data/setor/unidade

- Exportação para Excel com 1 clique

- Impressão otimizada

## 🗄️ Banco de Dados
```sql
-- Principais tabelas
itens           # Materiais do estoque
unidades        # Hospitais/Unidades
usuarios        # Usuários do sistema
requisicoes     # Solicitações pendentes
entregas        # Histórico de saídas
logs            # Auditoria completa
setores         # Departamentos da unidade
```
## 🚀 Deployment
### **Requisitos Mínimos:**
- PHP 8.1+ com PDO MySQL

- MySQL 8.0+ ou MariaDB 10.3+

- Apache 2.4+ ou Nginx

- 100MB espaço em disco

- 512MB RAM recomendado

### **Configuração Rápida:**
1. **Hospede** os arquivos no servidor web

2. **Importe** o SQL do banco de dados

3. **Configure** config/db.php

4. **Ajuste** permissões (chmod 755 para pastas)

5. **Acesse** e configure o primeiro admin

## 🤝 Contribuição
1. **Fork** o repositório

2. **Crie** uma branch (git checkout -b feature/nova-funcionalidade)

3. **Commit** suas mudanças (git commit -m 'Add: nova funcionalidade')

4. **Push** para a branch (git push origin feature/nova-funcionalidade)

5. **Abra** um Pull Request

## 📄 Licença
Distribuído sob licença MIT. Veja LICENSE para mais informações.
