## v0.3.1 (2026-06-03)

### Fix

- aguardar deploy-target disponível após reinicio do container
- add environment declaration to workflow jobs

## v0.3.0 (2026-06-02)

### Feat

- Adicionar wp:config:lock ao final da cadeia de deploy
- Adicionar tasks de provisionamento EasyEngine e corrigir escaping SSH

### Fix

- Alocar PTY para ee site clean e corrigir comandos DDEV

## v0.2.0 (2026-06-02)

### Feat

- Adaptar setup:scripts para estrutura por stack e tipo de projeto
- Incluir a pasta para arquivos de inicialização baseado nos scripts de importação
- Versão inicial do boilerplate

### Fix

- Remover o padrão em caso de variável não definida

### Refactor

- Modularizar deploy, migrar scripts do servidor para repo externo
- Incluir a variável de timezone no exemplo
