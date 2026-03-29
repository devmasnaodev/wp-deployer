# WP Deployer

WP Deployer é uma boilerplate para facilitar o deploy e gerenciamento de instalações WordPress em ambientes de desenvolvimento com DDEV e produção.

## Funcionalidades
- Instalação automatizada do WordPress
- Gerenciamento de ambientes (DDEV)
- Scripts de deploy customizados
- Suporte a múltiplos ambientes

## Estrutura do Projeto
- `deploy.php`: Script principal de deploy
- `scripts/`: Scripts auxiliares (ex: instalação do core)
- `init/`: Dados e arquivos de inicialização
- `web/`: Instalação do WordPress
- `vendor/`: Dependências gerenciadas pelo Composer

## Pré-requisitos
- [PHP 8.0+](https://www.php.net/)
- [Composer](https://getcomposer.org/)
- [DDEV](https://ddev.com/)

## Como usar
1. Clone o repositório:
   ```sh
   git clone https://github.com/devmasnaodev/wp-deployer.git
   cd wp-deployer
   ```

2. Instale as dependências:
   ```sh
   ddev start
   ```

## Instalação automática do WordPress no start

No `ddev start`, o projeto executa os comandos em sequência:

1. `composer-install-if-needed`
2. `import-seed`
3. `install-wp-if-needed`
4. `import-uploads`

Comportamento:
- Se existir `init/data/db.sql.gz`, o `import-seed` importa a base e a instalação via WP-CLI é ignorada.
- Se não existir seed e o WordPress ainda não estiver instalado, o `install-wp-if-needed` executa `wp core install` usando variáveis do `.env`.

### Criando um padrão de importação

Você pode criar um padrão de importação do projeto adicionando os arquivos de banco e uploads em `init/data`.

Para exportar a base de dados, utilize:

```sh
ddev export-db --file=init/data/db.sql.gz
```

Para exportar os arquivos de uploads, utilize:

```sh
tar -cvzf init/data/uploads.tar.gz web/wp-content/uploads
```

Variáveis esperadas no `.env`:
- `WP_URL`
- `WP_TITLE`
- `WP_ADMIN_USER`
- `WP_ADMIN_PASSWORD` (obrigatória para instalar)
- `WP_ADMIN_EMAIL`
- `WP_LOCALE` (opcional)

## Configuração do Deploy

Antes de rodar o deploy, crie um arquivo `.env` na raiz do projeto com os dados do servidor. Veja um exemplo de variáveis necessárias no arquivo `.env.example` (se disponível).

Se necessário, altere o usuário de deploy diretamente no arquivo `deploy.php` para refletir o usuário correto do seu servidor.

### Integração Contínua (CI)
Para uso em CI (ex: GitHub Actions), as variáveis de ambiente devem ser configuradas nos segredos do repositório no GitHub, em vez de um arquivo `.env` local.

## Observação Importante

No momento, o deploy está configurado para trabalhar com uma estrutura pré-definida de servidores de Produção e Staging utilizando EasyEngine. Caso utilize outra stack ou precise de ajustes, será necessário adaptar os scripts conforme sua infraestrutura.

## Contribuição
Pull requests são bem-vindos! Para grandes mudanças, abra uma issue primeiro para discutir o que você gostaria de modificar.

## Licença
Consulte o arquivo `LICENSE` para mais informações.
