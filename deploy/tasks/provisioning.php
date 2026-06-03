<?php

namespace Deployer;

use function Env\env;

task('composer:auth:upload', function () {
    $localAuth = DEPLOY_ROOT . '/auth.json';

    if (!file_exists($localAuth)) {
        throw new \Exception('auth.json não encontrado localmente.');
    }

    run('mkdir -p {{deploy_path}}/shared');
    upload($localAuth, '{{deploy_path}}/shared/auth.json');
    run('chmod 600 {{deploy_path}}/shared/auth.json');
})->desc('Envia auth.json para shared no servidor');

task('setup:scripts', function () {
    $baseDir          = dirname(get('deploy_path'));
    $remoteScriptsDir = "{$baseDir}/scripts";
    assert_within_domain($baseDir, $remoteScriptsDir);
    $stack            = get_prod_stack();
    $type             = get_project_type();
    $scriptsUrl       = get('scripts_base_url') . "/{$stack}/{$type}";
    $scripts          = ['backup-db.sh', 'backup-files.sh', 'restore.sh'];

    run("mkdir -p {$remoteScriptsDir}/lib");

    writeln("📥 Baixando lib/common.sh...");
    run("curl -fsSL {$scriptsUrl}/lib/common.sh -o {$remoteScriptsDir}/lib/common.sh");

    foreach ($scripts as $script) {
        writeln("📥 Baixando {$script}...");
        run("curl -fsSL {$scriptsUrl}/{$script} -o {$remoteScriptsDir}/{$script}");
    }

    run("chmod +x {$remoteScriptsDir}/*.sh");
    writeln("✅ Scripts instalados em {$remoteScriptsDir} ({$stack}/{$type})");
})->desc('Instala scripts de manutenção a partir do repositório remoto');

task('duplicati:register-backup-task', function () {
    assert_easyengine();

    $domain  = get('domain');
    $baseDir = dirname(get('deploy_path'));

    $b2Key   = env('B2_APPLICATION_KEY')    ?: getenv('B2_APPLICATION_KEY');
    $b2KeyId = env('B2_APPLICATION_KEY_ID') ?: getenv('B2_APPLICATION_KEY_ID');

    if (empty($b2Key) || empty($b2KeyId)) {
        throw new \Exception('B2_APPLICATION_KEY e B2_APPLICATION_KEY_ID devem estar definidos no .env');
    }

    $script = '/opt/easyengine/services/duplicati/create-duplicati-backup.sh';
    $cmd    = 'B2_APPLICATION_KEY=' . escapeshellarg($b2Key)
            . ' B2_APPLICATION_KEY_ID=' . escapeshellarg($b2KeyId)
            . ' ' . $script . ' ' . escapeshellarg($domain);

    writeln('📦 Registrando tarefa de backup no Duplicati para: ' . $domain);
    $output = run_on_management_host($cmd);
    writeln($output);

    $cronCmd = 'sudo ee cron create ' . escapeshellarg($domain)
             . ' --command=' . escapeshellarg("{$baseDir}/scripts/backup-db.sh")
             . ' --schedule=' . escapeshellarg('@daily');

    writeln('⏰ Registrando cron de backup do banco de dados...');
    $output = run_on_management_host($cronCmd);
    writeln($output);

    writeln('✅ Duplicati e cron configurados para: ' . $domain);
})->desc('Registra tarefa de backup no Duplicati e cron diário de banco via EasyEngine');

task('ee:site:create', function () {
    assert_easyengine();

    $domain     = get('domain');
    $siteTitle  = env('WP_TITLE')          ?: getenv('WP_TITLE')          ?: $domain;
    $adminEmail = env('WP_ADMIN_EMAIL')    ?: getenv('WP_ADMIN_EMAIL')    ?: 'webadmin@infografic.com.br';
    $adminUser  = env('WP_ADMIN_USER')     ?: getenv('WP_ADMIN_USER')     ?: 'infoadm';
    $adminPass  = env('WP_ADMIN_PASSWORD') ?: getenv('WP_ADMIN_PASSWORD');
    $phpVersion = env('EE_PHP_VERSION')    ?: getenv('EE_PHP_VERSION')
               ?: getenv('DDEV_PHP_VERSION') ?: '8.3';
    $dbName     = env('DBNAME')            ?: getenv('DBNAME');
    $dbUser     = env('DBUSER')            ?: getenv('DBUSER');
    $dbPass     = env('DBPASS')            ?: getenv('DBPASS');
    $dbHost     = env('DBHOST')            ?: getenv('DBHOST');
    $dbPrefix   = env('DBPREFIX')          ?: getenv('DBPREFIX')
               ?: env('WP_DB_PREFIX')      ?: getenv('WP_DB_PREFIX')      ?: 'wp_';

    foreach (['DBNAME' => $dbName, 'DBPASS' => $dbPass, 'DBHOST' => $dbHost, 'WP_ADMIN_PASSWORD' => $adminPass] as $var => $val) {
        if (empty($val)) {
            throw new \Exception("{$var} deve estar definido para criação do site.");
        }
    }

    $cmd = 'sudo ee site create ' . escapeshellarg($domain)
         . ' --type=wp'
         . ' --title='       . escapeshellarg($siteTitle)
         . ' --admin-email=' . escapeshellarg($adminEmail)
         . ' --admin-user='  . escapeshellarg($adminUser)
         . ' --admin-pass='  . escapeshellarg($adminPass)
         . ' --php='         . escapeshellarg($phpVersion)
         . ' --public-dir=current/web'
         . ' --dbname='      . escapeshellarg($dbName)
         . ' --dbuser='      . escapeshellarg($dbUser)
         . ' --dbpass='      . escapeshellarg($dbPass)
         . ' --dbhost='      . escapeshellarg($dbHost)
         . ' --dbprefix='    . escapeshellarg($dbPrefix)
         . ' --ssl=le'
         . ' --cache';

    writeln("🌐 Criando site EasyEngine: {$domain} (PHP {$phpVersion})...");
    $output = run_on_management_host($cmd);
    writeln($output);
    writeln("✅ Site criado: {$domain}");
})->desc('Cria site WordPress via EasyEngine no servidor de gerenciamento');

task('ee:configure-deploy-target', function () {
    assert_easyengine();

    $domain     = get('domain');
    $compose    = '/opt/easyengine/services/deploy-target/docker-compose.yml';
    $siteAppDir = "/opt/easyengine/sites/{$domain}/app/";
    $volumeLine = "      - /opt/easyengine/sites/{$domain}/app/:/var/www/{$domain}";

    writeln("🔧 Configurando volume do deploy-target para: {$domain}");

    $checkCmd = 'grep -qF ' . escapeshellarg($siteAppDir) . " {$compose} && echo EXISTS || echo MISSING";
    if (str_contains(trim(run_on_management_host($checkCmd)), 'EXISTS')) {
        writeln("⏭️  Volume já existe no docker-compose.yml, apenas reiniciando...");
        writeln(run_on_management_host("sudo docker-compose -f {$compose} up -d") ?: 'Container reiniciado.');
    } else {
        $awkCmd    = "awk '/## Volume do site EasyEngine/{print; print \"{$volumeLine}\"; next}1' {$compose}";
        $updateCmd = "{$awkCmd} | sudo tee {$compose} > /dev/null"
                   . " && sudo docker-compose -f {$compose} up -d";

        writeln(run_on_management_host($updateCmd) ?: 'Container reiniciado.');
    }

    $sshPort = currentHost()->getPort();
    writeln("⏳ Aguardando deploy-target disponível na porta {$sshPort}...");
    run_on_management_host("timeout 60 bash -c 'until nc -z 127.0.0.1 {$sshPort} 2>/dev/null; do sleep 1; done'");

    writeln("✅ Volume adicionado e deploy-target reiniciado para: {$domain}");
})->desc('Adiciona volume do site ao deploy-target e reinicia o container');

task('ee:prepare-htdocs', function () {
    $deployPath = get('deploy_path');
    $baseDir    = dirname($deployPath);
    assert_within_domain($deployPath, $baseDir);

    writeln("📋 Salvando wp-config.php gerado pelo EasyEngine...");
    run("cp {$deployPath}/current/wp-config.php {$baseDir}/wp-config.php");

    writeln("🗑️  Removendo current/ para o Deployer criar o symlink...");
    run("rm -rf {$deployPath}/current");

    writeln("✅ htdocs preparado para o primeiro deploy.");
})->desc('Salva wp-config.php do EasyEngine e limpa current/ antes do deploy inicial');

task('ee:setup-shared-wp-config', function () {
    $deployPath = get('deploy_path');
    $baseDir    = dirname($deployPath);
    $source     = "{$baseDir}/wp-config.php";
    $dest       = "{$deployPath}/shared/wp-config.php";
    assert_within_domain($dest);

    writeln("📋 Movendo wp-config.php para shared/...");
    run("mv {$source} {$dest}");
    writeln("✅ wp-config.php disponível em {$deployPath}/shared/");
})->desc('Move o wp-config.php salvo pelo EE para shared/ após o primeiro deploy');

task('init:generate-data', function () {
    writeln("🔄 Gerando dados de inicialização via ddev generate-init-data...");
    runLocally('.ddev/commands/host/generate-init-data');
    writeln("✅ Dados gerados em init/data/");
})->desc('Gera db.sql.gz e uploads.tar.gz localmente via DDEV');

task('ee:provision', function () {
    $stage = currentHost()->getAlias();

    invoke('ee:site:create');
    invoke('ee:configure-deploy-target');
    invoke('ee:prepare-htdocs');
    invoke('composer:auth:upload');
    invoke('ee:setup-shared-wp-config');

    writeln("🚀 Executando primeiro deploy em {$stage}...");
    runLocally("dep deploy {$stage}");

    invoke('init:generate-data');
    invoke('init:data:import');
    invoke('setup:scripts');
    invoke('db:replace-urls');
    invoke('services:clean');
})->desc('Provisiona site EasyEngine: criação, primeiro deploy, shared wp-config e importação de dados');
