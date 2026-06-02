# WP Deployer

Boilerplate para deploy e gerenciamento de instalações WordPress com DDEV (desenvolvimento) e EasyEngine (produção/staging).

## Funcionalidades

- Inicialização automática do WordPress no `ddev start`
- Deploy via [Deployer](https://deployer.org/) com cadeia de tarefas automatizada
- Provisionamento de ambientes EasyEngine (criação de site, shared wp-config, primeiro deploy)
- Instalação de scripts de manutenção remotos (backup, restore)
- Suporte a múltiplos ambientes com `.env` por stage

## Pré-requisitos

- [PHP 8.0+](https://www.php.net/)
- [Composer](https://getcomposer.org/)
- [DDEV](https://ddev.com/)

## Configuração de ambiente

O projeto usa arquivos `.env` por stage. Copie `.env.example` para `.env` e preencha os valores:

```sh
cp .env.example .env
```

| Arquivo | Uso |
|---|---|
| `.env` | Valores locais / desenvolvimento (base) |
| `.env.production` | Sobrescreve `.env` para produção |
| `.env.staging` | Sobrescreve `.env` para staging |

O stage é detectado automaticamente pelo argumento do `dep` (ex: `dep deploy production`) ou pela variável `DEPLOY_ENV=production`.

### Variáveis principais

```sh
PROJECT_TYPE=native        # native | bedrock
PROD_STACK=easyengine      # stack do servidor de produção

PROD_IP=0.0.0.0
PROD_PORT=2232
PROD_DOMAIN=example.com
STAGING_IP=0.0.0.0
STAGING_PORT=2232
STAGING_DOMAIN=staging.example.com

MGMT_USER=infoadm          # usuário SSH no host EasyEngine (porta 22)

# WordPress bootstrap
WP_TITLE="Meu Site"
WP_ADMIN_USER=admin
WP_ADMIN_PASSWORD=change-me
WP_ADMIN_EMAIL=admin@example.com
WP_TIMEZONE=America/Sao_Paulo
WP_DB_PREFIX=""
WP_LOCALE=pt_BR

# Banco de dados de produção
DBNAME=""
DBUSER=doadmin
DBPASS=""
DBHOST=""
DBPREFIX=""

# Backup Duplicati/B2 (opcional)
B2_APPLICATION_KEY=""
B2_APPLICATION_KEY_ID=""
```

## Desenvolvimento local

```sh
git clone https://github.com/devmasnaodev/wp-deployer.git
cd wp-deployer
cp .env.example .env   # preencha WP_ADMIN_PASSWORD no mínimo
ddev start
```

No `ddev start`, o projeto executa em sequência:

1. `composer-install-if-needed` — instala dependências se necessário
2. `import-seed` — importa `init/data/db.sql.gz` se existir
3. `install-wp-if-needed` — executa `wp core install` se o WordPress ainda não estiver instalado
4. `import-uploads` — extrai `init/data/uploads.tar.gz` se existir

Se `init/data/db.sql.gz` existir, a instalação via WP-CLI é ignorada.

### Gerando dados de inicialização

Para criar o padrão de importação do projeto (banco + uploads):

```sh
ddev generate-init-data
```

O comando exporta e salva em `init/data/`:
- `db.sql` e `db.sql.gz`
- `uploads.tar.gz`
- `webp-express.tar.gz` (se o diretório existir)

## Deploy

O deploy é feito via Deployer. As configurações de hosts ficam em `deploy/config.php`.

```sh
dep deploy production
dep deploy staging
```

### Cadeia de deploy

```
validate:env
backup:files → backup:db
→ deploy (upload + composer install)
→ wordpress:update-db
→ wordpress:cache
→ services:restart
→ services:clean
→ wp:config:lock
```

Em caso de falha, `deploy:unlock` é executado automaticamente. Rollback com restore opcional:

```sh
dep rollback production                    # rollback simples
RESTORE_ON_ROLLBACK=1 dep rollback production  # rollback + restore de arquivos/banco
```

### CI (GitHub Actions)

As variáveis de ambiente são configuradas nos segredos do repositório. O workflow `.github/workflows/deploy.yml` é acionado manualmente (`workflow_dispatch`) e roda em um runner self-hosted com a tag `deployer`.

## Provisionamento EasyEngine

Para provisionar um novo ambiente do zero (criação do site, primeiro deploy, importação de dados):

```sh
dep ee:provision production
dep ee:provision staging
```

O `ee:provision` executa em sequência:

1. `ee:site:create` — cria o site no EasyEngine com SSL, cache e banco externo
2. `ee:configure-deploy-target` — adiciona volume do site ao container deploy-target
3. `ee:prepare-htdocs` — salva `wp-config.php` gerado pelo EE e limpa `current/`
4. `composer:auth:upload` — envia `auth.json` para `shared/` no servidor
5. `ee:setup-shared-wp-config` — move `wp-config.php` para `shared/`
6. Primeiro `dep deploy <stage>`
7. `init:generate-data` + `init:data:import` — gera e importa dados locais

### Tasks de provisionamento individuais

```sh
dep ee:site:create production          # só criação do site
dep ee:configure-deploy-target production
dep ee:prepare-htdocs production
dep ee:setup-shared-wp-config production
dep composer:auth:upload production
```

## Setup de scripts de manutenção

Instala scripts de backup e restore no servidor a partir do repositório remoto:

```sh
dep setup:scripts production
dep setup:scripts staging
```

Scripts instalados em `<base_dir>/scripts/`: `backup-db.sh`, `backup-files.sh`, `restore.sh` e `lib/common.sh`. O conjunto de scripts é selecionado automaticamente pelo `PROD_STACK` e `PROJECT_TYPE`.

## Tasks de manutenção

```sh
# Importação de dados
dep init:data:import production        # importa banco + uploads + webp-express
dep db:import production               # só banco (db.sql)
dep uploads:import production          # só uploads
dep db:replace-urls production         # substitui URLs do DDEV para produção

# wp-config.php
dep wp:config:lock production          # define DISALLOW_FILE_EDIT, DISALLOW_FILE_MODS, etc.
dep wp:config:unlock production        # remove as constantes acima

# Backup Duplicati
dep duplicati:register-backup-task production  # registra tarefa e cron diário
```

## Estrutura do projeto

```
.ddev/
  commands/
    host/generate-init-data   # exporta banco e uploads para init/data
    web/install-wp-if-needed  # instala WordPress via WP-CLI no start
deploy/
  bootstrap.php               # carregamento de .env por stage
  config.php                  # hosts (production, staging)
  helpers.php                 # funções auxiliares (ee_shell, run_on_management_host, …)
  tasks/
    deploy-chain.php          # cadeia de deploy e hooks
    provisioning.php          # provisionamento EasyEngine e scripts
    maintenance.php           # db:import, uploads:import, wp:config:lock, …
init/data/                    # seeds de banco e uploads (não versionados)
scripts/                      # install-wp-core.php (bootstrap local)
web/                          # instalação WordPress
deploy.php                    # entrypoint do Deployer
```

## Contribuição

Pull requests são bem-vindos. Para grandes mudanças, abra uma issue primeiro.

## Licença

Consulte o arquivo `LICENSE` para mais informações.
