<?php

namespace Deployer;

if (file_exists(__DIR__ . '/vendor/autoload.php')) {
    require_once __DIR__ . '/vendor/autoload.php';
} else {
    $paths = [
        $_SERVER['HOME'] . '/.composer/vendor/autoload.php',
        '/home/runner/.composer/vendor/autoload.php'
    ];
    
    foreach ($paths as $path) {
        if (file_exists($path)) {
            require_once $path;
            break;
        }
    }
}

if (file_exists(__DIR__ . '/.env')) {
    $dotenv = \Dotenv\Dotenv::createImmutable(__DIR__);
    $dotenv->load();
}

use function Env\env;

\Env\Env::$options = 31;

// Host - Production
$prod_ip = env('PROD_IP') ?: getenv('PROD_IP');
$prod_port = (int) (env('PROD_PORT') ?: getenv('PROD_PORT'));
$prod_domain = env('PROD_DOMAIN') ?: getenv('PROD_DOMAIN');

// Host - Staging
$staging_ip = env('STAGING_IP') ?: getenv('STAGING_IP');
$staging_port = (int) (env('STAGING_PORT') ?: getenv('STAGING_PORT'));
$staging_domain = env('STAGING_DOMAIN') ?: getenv('STAGING_DOMAIN');

function is_first_release() {
    $latestReleasePath = get('deploy_path') . '/.dep/latest_release';
    // Se o arquivo latest_release não existe, é o primeiro release
    return !test("[ -f {$latestReleasePath} ]");
}

// Helper para executar comandos via SSH/CI no servidor de gerenciamento
function run_on_management_host($cmd) {
    $mgmtHost = get('mgmt_host', get('hostname'));
    $mgmtUser = get('mgmt_user', 'root');
    $isCI = getenv('GITHUB_ACTIONS') === 'true';
    if ($isCI) {
        return runLocally('ssh -o StrictHostKeyChecking=no ' . $mgmtUser . '@' . $mgmtHost . ' "' . $cmd . '"');
    } else {
        return run('ssh -o StrictHostKeyChecking=no ' . $mgmtUser . '@10.0.0.1 "' . $cmd . '"');
    }
}

require 'recipe/common.php';

// Configurações básicas
set('application', 'WordPress Bedrock');
set('user', 'www-data');
set('keep_releases', 5);
set('composer_options', '--no-dev --prefer-dist --optimize-autoloader');

// Desabilitar git (modo CI)
set('repository', '.');
set('branch', 'main');
set('git_strategy', false);

// Define o target dinamicamente como a branch atual do git
set('target', function () {
    $branch = getenv('GITHUB_REF_NAME') ?: getenv('BRANCH_NAME');
    if (empty($branch) && file_exists(__DIR__ . '/.git')) {
        $branch = trim(shell_exec('git rev-parse --abbrev-ref HEAD 2>/dev/null'));
    }
    return $branch ?: 'unknown';
});

// Configurações WordPress
set('shared_files', ['wp-config.php', 'nginx.conf']);
set('shared_dirs', ['web/wp-content/uploads']);
set('writable_dirs', ['web/wp-content/uploads']);
set('writable_mode', 'chmod');

host('production')
    ->setHostname($prod_ip)
    ->setPort($prod_port)
    ->set('remote_user', 'www-data')
    ->set('deploy_path', '/var/www/' . $prod_domain . '/htdocs')
    ->set('domain', $prod_domain)
    ->set('mgmt_host', $prod_ip)
    ->set('mgmt_user', 'root')
    ->set('branch', 'main')
    ->set('env_required', ['PROD_IP', 'PROD_PORT', 'PROD_DOMAIN']);

    
host('staging')
    ->setHostname($staging_ip)
    ->setPort($staging_port)
    ->set('remote_user', 'www-data')
    ->set('deploy_path', '/var/www/' . $staging_domain . '/htdocs')
    ->set('domain', $staging_domain)
    ->set('mgmt_host', '10.0.0.1')
    ->set('mgmt_user', 'root')
    ->set('branch', 'develop')
    ->set('env_required', ['STAGING_IP', 'STAGING_PORT', 'STAGING_DOMAIN']);

task('validate:env', function () {
    $required = get('env_required', []);

    foreach ($required as $key) {
        $value = env($key) ?: getenv($key);
        if (empty($value)) {
            throw new \Exception($key . ' environment variable is required');
        }
    }
});


// Task customizada para upload (substitui a padrão)
task('deploy:update_code', function () {
    run('mkdir -p {{release_path}}');

    $commitSha = getenv('COMMIT_SHA');
    if (empty($commitSha) && file_exists(__DIR__ . '/.git')) {
        $commitSha = trim(runLocally('git rev-parse HEAD'));
    }
    $revision = $commitSha ? substr($commitSha, 0, 8) : 'unknown';

    $tmpDir = sys_get_temp_dir();
    $archiveName = 'deploy-' . ($revision ?: 'unknown') . '.tar.gz';
    $archivePath = $tmpDir . DIRECTORY_SEPARATOR . $archiveName;

    if (file_exists($archivePath)) {
        @unlink($archivePath);
    }

    runLocally('git archive --format=tar --worktree-attributes HEAD | gzip > ' . escapeshellarg($archivePath));
    upload($archivePath, '{{release_path}}/' . $archiveName);
    run('cd {{release_path}} && tar -xzf ' . $archiveName . ' && rm -f ' . $archiveName);
    run('echo ' . escapeshellarg($revision) . ' > {{release_path}}/REVISION');

});

// Task para atualizar o releases_log com o autor correto
task('deploy:update_releases_log', function () {
    $commitAuthor = getenv('COMMIT_AUTHOR');

    // Se não estiver no CI, usar git local
    if (empty($commitAuthor) && file_exists(__DIR__ . '/.git')) {
        $commitAuthor = trim(shell_exec('git log -1 --pretty=format:"%an" 2>/dev/null') ?: '');
    }

    if (!empty($commitAuthor)) {
        $content = run('cat {{deploy_path}}/.dep/releases_log');
        $lines = explode("\n", trim($content));

        if (!empty($lines)) {
            $lastLine = array_pop($lines);
            $data = json_decode($lastLine, true);

            if ($data) {
                $data['user'] = $commitAuthor;
                $lines[] = json_encode($data);

                $keepReleases = (int) get('keep_releases', 5);
                if (count($lines) > $keepReleases) {
                    $lines = array_slice($lines, -$keepReleases);
                }

                run('echo ' . escapeshellarg(implode("\n", $lines)) . ' > {{deploy_path}}/.dep/releases_log');
            }
        }
    }
});

task('deploy:vendors', function () {
    if (!commandExist('unzip')) {
        warning('To speed up composer installation setup "unzip" command with PHP zip extension.');
    }

    run('cd {{release_or_current_path}} && {{bin/composer}} {{composer_action}} {{composer_options}} 2>&1');
});

// Backup dos arquivos - executa antes do deploy
task('backup:files', function () {
    if (is_first_release()) {
        writeln('ℹ️  Primeiro release detectado, backup de arquivos não será executado.');
        return;
    }

    writeln('🔒 Creating backup...');

    $deployPath = get('deploy_path');
    $baseDir = dirname($deployPath);

    $backupScript = "{$baseDir}/scripts/backup-files.sh";
    $scriptExists = test("[ -f {$backupScript} ]");

    if ($scriptExists) {
        writeln("📦 Running backup script: {$backupScript}");
        run("cd {$baseDir} && ./scripts/backup-files.sh --with-shared-files");
        writeln('✅ Backup completed successfully');
    } else {
        writeln("⚠️  Warning: Backup script not found at {$backupScript}");
        writeln('⏭️  Skipping backup...');
    }
});

// Backup do banco de dados
task('backup:db', function () {
    if (is_first_release()) {
        writeln('ℹ️  Primeiro release detectado, backup do banco não será executado.');
        return;
    }

    writeln('🔒 Creating database backup...');

    $deployPath = get('deploy_path');
    $baseDir = dirname($deployPath);

    $backupDbScript = "{$baseDir}/scripts/backup-db.sh";
    $scriptExists = test("[ -f {$backupDbScript} ]");

    if ($scriptExists) {
        writeln("📦 Running DB backup script: {$backupDbScript}");
        run("cd {$baseDir} && ./scripts/backup-db.sh ");
        writeln('✅ Database backup completed successfully');
    } else {
        writeln("⚠️  Warning: DB backup script not found at {$backupDbScript}");
        writeln('⏭️  Skipping database backup...');
    }
});


// Task para restaurar arquivos compartilhados e banco de dados
task('restore:latest', function () {
    writeln('♻️  Restaurando arquivos compartilhados e banco de dados...');
    $deployPath = get('deploy_path');
    $baseDir = dirname($deployPath);
    $restoreScript = "{$baseDir}/scripts/restore.sh";
    $scriptExists = test("[ -f {$restoreScript} ]");
    if ($scriptExists) {
        writeln("🔄 Executando restore: {$restoreScript} ");
        run("cd {$baseDir} && ./scripts/restore.sh");
        writeln('✅ Restore concluído com sucesso');
    } else {
        writeln("⚠️  Warning: Restore script not found at {$restoreScript}");
        writeln('⏭️  Skipping restore...');
    }
});


// WordPress tasks
task('wordpress:update-db', function () {
    $domain = get('domain');
    writeln('Executando update-db via ee shell para o domínio: ' . $domain);
    $cmd = 'sudo ee shell ' . $domain . ' --command=\"wp core update-db\"';
    $output = run_on_management_host($cmd);
    writeln('Saída do comando:');
    writeln($output);
});

task('wordpress:cache', function () {
    $domain = get('domain');
    writeln('Executando cache flush via ee shell para o domínio: ' . $domain);
    $cmd = 'sudo ee shell ' . $domain . ' --command=\"wp cache flush\"';
    $output = run_on_management_host($cmd);
    writeln('Saída do comando:');
    writeln($output);
    $cmd = 'sudo ee shell ' . $domain . ' --command=\"wp redis enable\"';
    $output = run_on_management_host($cmd);
    writeln('Saída do comando:');
    writeln($output);
});

// Restart services (via SSH from deployer host)
task('services:restart', function () {
    $domain = get('domain');
    writeln('🔄 Restarting services via ee site restart para o domínio: ' . $domain);
    $cmd = 'sudo ee site restart ' . $domain;
    $output = run_on_management_host($cmd);
    writeln('Saída do comando:');
    writeln($output);
});

// Clear Redis Cache (via SSH from deployer host)
task('services:clean', function () {
    $domain = get('domain');
    writeln('🔄 Clearing Redis cache via ee site clean para o domínio: ' . $domain);
    $cmd = 'sudo ee site clean ' . $domain;
    $output = run_on_management_host($cmd);
    writeln('Saída do comando:');
    writeln($output);
});

// Hooks
before('deploy:prepare', 'backup:files');
before('deploy:prepare', 'backup:db');
before('deploy:prepare', 'validate:env');
after('deploy:shared', 'deploy:vendors');
after('deploy:update_code', 'deploy:update_releases_log');
after('deploy:symlink', 'wordpress:update-db');
after('wordpress:update-db', 'wordpress:cache');
after('wordpress:cache', 'services:restart');
after('services:restart', 'services:clean');
after('deploy:failed', 'deploy:unlock');

// Hook para executar restore após rollback, se variável de ambiente estiver definida
after('rollback', function () {
    $restoreOnRollback = getenv('RESTORE_ON_ROLLBACK');
    if ($restoreOnRollback === '1' || $restoreOnRollback === 'true') {
        invoke('restore:latest');
    } else {
        writeln('ℹ️  Restore não executado automaticamente após rollback. Defina RESTORE_ON_ROLLBACK=1 para ativar.');
    }
});

desc('Deploy WordPress via CI upload');

// Reinicia serviços após rollback
after('rollback', 'services:restart');