<?php

function safe_exec($cmd, &$output = null, &$exitCode = null): string
{
    $output = [];
    exec($cmd . ' 2>&1', $output, $exitCode);
    return implode("\n", $output);
}

function deleteDirectory($dir): void
{
    if (!is_dir($dir)) return;
    foreach (scandir($dir) as $item) {
        if ($item == '.' || $item == '..') continue;
        $path = "$dir/$item";
        is_dir($path) ? deleteDirectory($path) : unlink($path);
    }
    rmdir($dir);
}

function extractVersionFromUrl($url): string|null
{
    if (preg_match('#/tags/(v?[0-9]+\.[0-9]+\.[0-9]+)(\.zip)?$#', $url, $matches)) {
        return $matches;
    }
    return null;
}

function extractZipArchive($zipFile, $folder, $name, $version, $actualHash, &$lockData, $forceUpdate)
{
    $zip = new ZipArchive();
    if ($zip->open($zipFile) === TRUE) {
        if ($forceUpdate && is_dir($folder)) {
            deleteDirectory($folder);
        }
        mkdir($folder);
        $zip->extractTo($folder);
        $zip->close();
        echo "Extracted to $folder\n";

        $lockData[$name] = [
            'version' => $version,
            'sha256' => $actualHash,
            'git' => !empty($info['git'])
        ];
    } else {
        echo "Failed to unzip $name\n";
    }
    unlink($zipFile);
}

function downloadAndExtract($name, $info, $libDir, &$lockData, $forceUpdate = false): void
{
    $safeName = str_replace('/', '-', $name);
    $version = "{$safeName}@{$info['version']}" ?? extractVersionFromUrl($info['url'])[1];
    $folder = "$libDir/{$version}";

    if (!$forceUpdate && is_dir($folder)) {
        echo "$name v{$info['version']} already exists, skipping...\n";
        return;
    }

    echo ($forceUpdate ? "Updating" : "Downloading") . " $name v{$info['version']}...\n";

    $zipFile = tempnam(sys_get_temp_dir(), 'dep') . '.zip';

    if (!empty($info['git'])) {
        $tmpDir = sys_get_temp_dir() . '/' . uniqid('gitpkg_');

        $gitCmd = "git clone --depth=1 {$info['url']} $tmpDir";
        $gitOutput = safe_exec($gitCmd, $output, $exitCode);

        if ($exitCode !== 0) {
            echo "Git clone failed for $name:\n$gitOutput\n";
            return;
        }

        if (!empty($info['exclude'])) {
            foreach ($info['exclude'] as $pattern) {
                $files = glob("$tmpDir/$pattern", GLOB_BRACE);
                foreach ($files as $file) {
                    is_dir($file) ? deleteDirectory($file) : unlink($file);
                }
            }
        }

        $zip = new ZipArchive();
        if ($zip->open($zipFile, ZipArchive::CREATE | ZipArchive::OVERWRITE) === TRUE) {
            $files = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($tmpDir, RecursiveDirectoryIterator::SKIP_DOTS),
                RecursiveIteratorIterator::LEAVES_ONLY
            );

            foreach ($files as $file) {
                $filePath = $file->getRealPath();
                $relativePath = substr($filePath, strlen($tmpDir) + 1);
                $zip->addFile($filePath, $relativePath);
            }

            $zip->close();
        } else {
            echo "Failed to create zip for $name\n";
            return;
        }

        deleteDirectory($tmpDir);
    } else {
        file_put_contents($zipFile, file_get_contents($info['url']));
    }

    $actualHash = hash_file('sha256', $zipFile);

    extractZipArchive($zipFile, $folder, $name, $version, $actualHash, $lockData, $forceUpdate);
}

function uninstallPackage($name, $libDir, $lockFile, &$lockData)
{
    if (!isset($lockData[$name])) {
        echo "Package $name is not installed.\n";
        return;
    }

    $safeName = str_replace('/', '-', $name);
    $version = $lockData[$name]['version'];
    $folder = $libDir . DIRECTORY_SEPARATOR . "{$safeName}@{$version}";

    echo $folder;

    if (file_exists($folder)) {
        deleteDirectory($folder);
        echo "Removed directory: $folder\n";
    }

    unset($lockData[$name]);
    file_put_contents($lockFile, json_encode($lockData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    echo "Uninstalled $name\n";
}

$deps = json_decode(file_get_contents(__DIR__ . DIRECTORY_SEPARATOR . 'deps.json'), true);
$libDir = __DIR__ . DIRECTORY_SEPARATOR . 'vendor';
$lockFile = __DIR__ . DIRECTORY_SEPARATOR . 'deps.lock';
$lockData = file_exists($lockFile) ? json_decode(file_get_contents($lockFile), true) : [];

if (!is_dir($libDir)) {
    mkdir($libDir, 0777, true);
}

var_dump($argv, $argc);

$cmd = $argv[1] ?? null;
$pkg = $argv[2] ?? null;

if ($cmd === '--add' && !empty($pkg)) {
    $url = $argv[3] ?? null;

    if (!$url) {
        echo "Please specify a url for the package!" . PHP_EOL;
        exit(1);
    }

    if ($url) {
        // Ad-hoc package info
        $deps[$pkg] = [
            'url' => $url,
            // version will be auto-parsed from URL inside downloadAndExtract()
        ];

        $info = $deps[$pkg];

        downloadAndExtract($packageName, $info, $libDir, $lock, $lockData, false);

        // Save to lock file after install
        file_put_contents($lockFile, json_encode($lockData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    exit(0);
} elseif ($cmd === '--update' && $pkg) {
    if (isset($deps[$pkg])) {
        downloadAndExtract($pkg, $deps[$pkg], $libDir, $lockData, true);
    } else {
        echo "Package $pkg not found in deps.json\n";
    }
} elseif ($cmd === '--update') {
    foreach ($deps as $name => $info) {
        downloadAndExtract($name, $info, $libDir, $lockData, true);
    }
} elseif ($cmd === '--uninstall' && $pkg) {
    uninstallPackage($pkg, $libDir, $lockFile, $lockData);
} elseif ($cmd === '--phar') {
    $out = __DIR__ . DIRECTORY_SEPARATOR . "build" . DIRECTORY_SEPARATOR . "bundle.phar";
    if (!is_dir(dirname($out))) mkdir(dirname($out), 0777, true);
    if (file_exists($out)) unlink($out);

    $phar = new Phar($out);

    // Build from all PHP and JSON files, exclude build/, vendor/, .git/, and zip files
    $phar->buildFromDirectory(__DIR__, '/\.(php|json)$/i');
    $phar->compressFiles(Phar::GZ);

    // Custom stub with shebang + CLI entry main.php
    $stub = <<<PHP
#!/usr/bin/env php
<?php
Phar::mapPhar('bundle.phar');
require 'phar://bundle.phar/autoload.php';
require 'phar://bundle.phar/main.php';
__HALT_COMPILER();
PHP;

    $phar->setStub($stub);
    echo "Created $out\n";
} else {
    foreach ($deps as $name => $info) {
        downloadAndExtract($name, $info, $libDir, $lockData);
    }
}

file_put_contents($lockFile, json_encode($lockData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
