<?php

namespace Deployer;

use function Env\env;

task('validate:env', function () {
    $required = get('env_required', []);

    foreach ($required as $key) {
        $value = env($key) ?: getenv($key);
        if (empty($value)) {
            throw new \Exception($key . ' environment variable is required');
        }
    }
});

task('deploy:update_code', function () {
    run('mkdir -p {{release_path}}');

    $commitSha = getenv('COMMIT_SHA');
    if (empty($commitSha) && file_exists(DEPLOY_ROOT . '/.git')) {
        $commitSha = trim(runLocally('git rev-parse HEAD'));
    }
    $revision = $commitSha ? substr($commitSha, 0, 8) : 'unknown';

    $tmpDir      = sys_get_temp_dir();
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

task('deploy:update_releases_log', function () {
    $commitAuthor = getenv('COMMIT_AUTHOR');

    if (empty($commitAuthor) && file_exists(DEPLOY_ROOT . '/.git')) {
        $commitAuthor = trim(shell_exec('git log -1 --pretty=format:"%an" 2>/dev/null') ?: '');
    }

    if (!empty($commitAuthor)) {
        $content = run('cat {{deploy_path}}/.dep/releases_log');
        $lines   = explode("\n", trim($content));

        if (!empty($lines)) {
            $lastLine = array_pop($lines);
            $data     = json_decode($lastLine, true);

            if ($data) {
                $data['user'] = $commitAuthor;
                $lines[]      = json_encode($data);

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

task('backup:files', function () {
    if (is_first_release()) {
        writeln('ℹ️  Primeiro release detectado, backup de arquivos não será executado.');
        return;
    }

    writeln('🔒 Creating backup...');
    $baseDir      = dirname(get('deploy_path'));
    $backupScript = "{$baseDir}/scripts/backup-files.sh";

    if (test("[ -f {$backupScript} ]")) {
        writeln("📦 Running backup script: {$backupScript}");
        run("cd {$baseDir} && ./scripts/backup-files.sh --with-shared-files");
        writeln('✅ Backup completed successfully');
    } else {
        writeln("⚠️  Warning: Backup script not found at {$backupScript}");
        writeln('⏭️  Skipping backup...');
    }
});

task('backup:db', function () {
    if (is_first_release()) {
        writeln('ℹ️  Primeiro release detectado, backup do banco não será executado.');
        return;
    }

    writeln('🔒 Creating database backup...');
    $baseDir        = dirname(get('deploy_path'));
    $backupDbScript = "{$baseDir}/scripts/backup-db.sh";

    if (test("[ -f {$backupDbScript} ]")) {
        writeln("📦 Running DB backup script: {$backupDbScript}");
        run("cd {$baseDir} && ./scripts/backup-db.sh");
        writeln('✅ Database backup completed successfully');
    } else {
        writeln("⚠️  Warning: DB backup script not found at {$backupDbScript}");
        writeln('⏭️  Skipping database backup...');
    }
});

task('wordpress:update-db', function () {
    $domain = get('domain');
    writeln('Executando update-db via ee shell para o domínio: ' . $domain);
    $output = ee_shell($domain, 'wp core update-db');
    writeln($output);
});

task('wordpress:cache', function () {
    $domain = get('domain');

    writeln('🧹 Limpando cache...');
    writeln(ee_shell($domain, 'wp cache flush'));

    writeln('🔴 Ativando Redis (object-cache.php)...');
    run('ln -sf {{current_path}}/web/wp-content/plugins/wp-redis/object-cache.php '
      . '{{current_path}}/web/wp-content/object-cache.php');
    writeln('✅ object-cache.php symlink criado.');
});

task('services:restart', function () {
    $domain = get('domain');
    writeln('🔄 Restarting services via ee site restart para o domínio: ' . $domain);
    $output = run_on_management_host('sudo ee site restart ' . $domain);
    writeln($output);
});

task('services:clean', function () {
    $domain = get('domain');
    writeln('🔄 Clearing Redis cache via ee site clean para o domínio: ' . $domain);
    $output = run_on_management_host_pty('sudo ee site clean ' . $domain);
    writeln($output);
});

// Hooks
before('deploy:prepare', 'validate:env');
before('deploy:prepare', 'backup:files');
before('deploy:prepare', 'backup:db');
after('deploy:shared', 'deploy:vendors');
after('deploy:update_code', 'deploy:update_releases_log');
after('deploy:symlink', 'wordpress:update-db');
after('wordpress:update-db', 'wordpress:cache');
after('wordpress:cache', 'services:restart');
after('services:restart', 'services:clean');
after('services:clean', 'wp:config:lock');
after('deploy:failed', 'deploy:unlock');

after('rollback', function () {
    $restoreOnRollback = getenv('RESTORE_ON_ROLLBACK');
    if ($restoreOnRollback === '1' || $restoreOnRollback === 'true') {
        invoke('restore:latest');
    } else {
        writeln('ℹ️  Restore não executado automaticamente após rollback. Defina RESTORE_ON_ROLLBACK=1 para ativar.');
    }
});

after('rollback', 'services:restart');

desc('Deploy WordPress via CI upload');
