<?php

$projectRoot = dirname(__DIR__);
$webDir = $projectRoot . DIRECTORY_SEPARATOR . 'web';
$coreDir = $projectRoot . DIRECTORY_SEPARATOR . 'wp';

if (!is_dir($coreDir)) {
    fwrite(STDERR, "Core directory not found at {$coreDir}. Run Composer install/update first.\n");
    exit(1);
}

if (!is_dir($webDir)) {
    if (!mkdir($webDir, 0755, true) && !is_dir($webDir)) {
        fwrite(STDERR, "Failed to create web directory at {$webDir}\n");
        exit(1);
    }
}

$exclude = [
    'wp-content',
    'wp-config.php',
];

$copyRecursive = function (string $source, string $dest) use (&$copyRecursive, $exclude) {
    $baseName = basename($source);
    if (in_array($baseName, $exclude, true)) {
        return;
    }

    if (is_dir($source)) {
        if (!is_dir($dest)) {
            if (!mkdir($dest, 0755, true) && !is_dir($dest)) {
                fwrite(STDERR, "Failed to create directory {$dest}\n");
                exit(1);
            }
        }
        $items = scandir($source);
        if ($items === false) {
            fwrite(STDERR, "Failed to read directory {$source}\n");
            exit(1);
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $copyRecursive($source . DIRECTORY_SEPARATOR . $item, $dest . DIRECTORY_SEPARATOR . $item);
        }
        return;
    }

    if (!copy($source, $dest)) {
        fwrite(STDERR, "Failed to copy {$source} to {$dest}\n");
        exit(1);
    }
};

$copyRecursive($coreDir, $webDir);

$removeRecursive = function (string $path) use (&$removeRecursive) {
    if (!file_exists($path)) {
        return;
    }
    if (is_dir($path)) {
        $items = scandir($path);
        if ($items !== false) {
            foreach ($items as $item) {
                if ($item === '.' || $item === '..') {
                    continue;
                }
                $removeRecursive($path . DIRECTORY_SEPARATOR . $item);
            }
        }
        rmdir($path);
        return;
    }
    unlink($path);
};

$removeRecursive($coreDir);
echo "WordPress core movido de {$coreDir} para {$webDir}\n";
