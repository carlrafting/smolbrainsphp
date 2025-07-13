<?php

const VENDOR_DIR = __DIR__ . DIRECTORY_SEPARATOR . 'vendor';
const LOCKFILE = __DIR__ . DIRECTORY_SEPARATOR . 'deps.lock';
const DEPSFILE = __DIR__ . DIRECTORY_SEPARATOR . 'deps.json';

function iterateArgs(array $args, int $max = 1)
{
    for ($count = 0; $count < $max; $count += 1) {
        $arg = $args[$count];
        var_dump($arg);
    }
}

function extractPackage(
    string $zipFile,
    string $name,
    array $info,
    string $libDir,
    array &$lockData,
    bool $force = false
): void {
    $safeName = str_replace('/', '-', $name);
    $folder = "$libDir/{$safeName}@{$info['version']}";

    $zip = new ZipArchive();
    if ($zip->open($zipFile) === true) {
        if ($force && is_dir($folder)) {
            deleteDirectory($folder);
        }
        mkdir($folder, 0777, true);
        $zip->extractTo($folder);
        $zip->close();
        echo color('green', "Extracted to $folder\n");

        $lockData[$name] = [
            'version' => $info['version'],
            'sha256' => hash_file('sha256', $zipFile),
            'git' => $info['git'],
        ];
    } else {
        echo color('red', "Failed to unzip $name\n");
    }

    unlink($zipFile);
}

function init(array $args, string $filename): void
{
    $path = $args[1] ?? null;

    if (!$path) {
        echo color('cyan', "No path provided, using current directory..." . PHP_EOL);
        $path = '.';
    }

    $displayPath = realpath($path) ?? $path;
    echo color('cyan', "Initialize project at: $displayPath" . PHP_EOL);
    unset($displayPath);

    if (file_exists($filename)) {
        throw new Exception("File already exists: $filename!");
    }

    file_put_contents(
        $path
            ? $path . DIRECTORY_SEPARATOR . $filename
            : $filename,
        "{}"
    );
}

function color(string $color, string $text): string
{
    $colors = [
        'green'  => "\033[32m",
        'red'    => "\033[31m",
        'yellow' => "\033[33m",
        'cyan'   => "\033[36m",
        'reset'  => "\033[0m"
    ];
    return ($colors[$color] ?? '') . $text . $colors['reset'] . PHP_EOL;
}

function deleteDirectory(string $dir): void
{
    if (!is_dir($dir)) return;

    $items = array_diff(scandir($dir), ['.', '..']);
    foreach ($items as $item) {
        $path = "$dir/$item";
        is_dir($path) ? deleteDirectory($path) : unlink($path);
    }

    rmdir($dir);
}

function uninstallPackage(string $name, string $libDir, string $lockFile, array &$lockData): void
{
    if (!isset($lockData[$name])) {
        echo color('red', "Package $name is not installed.\n");
        return;
    }

    $safeName = str_replace('/', '-', $name);
    $version = $lockData[$name]['version'];
    $folder = "$libDir/{$safeName}@{$version}";

    if (is_dir($folder)) {
        deleteDirectory($folder);
        echo color('yellow', "Removed directory: $folder\n");
    }

    unset($lockData[$name]);
    file_put_contents($lockFile, json_encode($lockData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    echo color('green', "Uninstalled $name\n");
}

function cloneGitRepository(string $url, string &$tag, string &$outputZipFile, array $excludePatterns = []): bool
{
    $tmpDir = sys_get_temp_dir() . '/' . uniqid('gitpkg_');
    $cloneCmd = "git clone --depth 1 --branch $tag $url $tmpDir 2>&1";
    $output = shell_exec($cloneCmd);

    if (!is_dir($tmpDir) || !file_exists("$tmpDir/.git")) {
        echo "Git output:\n$output\n";
        return false;
    }

    foreach ($excludePatterns as $pattern) {
        $files = glob("$tmpDir/$pattern", GLOB_BRACE);
        foreach ($files as $file) {
            is_dir($file) ? deleteDirectory($file) : unlink($file);
        }
    }

    $zip = new ZipArchive();
    if ($zip->open($outputZipFile, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true) {
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($tmpDir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::LEAVES_ONLY
        );

        foreach ($files as $file) {
            $realPath = $file->getRealPath();
            $relativePath = substr($realPath, strlen($tmpDir) + 1);
            $zip->addFile($realPath, $relativePath);
        }

        $zip->close();
    }

    deleteDirectory($tmpDir);
    return true;
}

function installPackage(string $name, array $info, string $libDir, array &$lockData, bool $force = false): void
{
    $safeName = str_replace('/', '-', $name);

    if (empty($info['version']) && !empty($info['url'])) {
        if (
            preg_match('/\/releases\/download\/v?([\d\.\-a-zA-Z]+)/', $info['url'], $matches) ||
            preg_match('/\/archive\/refs\/tags\/v?([\d\.\-a-zA-Z]+)\.zip/', $info['url'], $matches)
        ) {
            $info['version'] = $matches[1];
        } else {
            echo color('red', "Could not parse version from URL for $name\n");
            return;
        }
    }

    $folder = "$libDir/{$safeName}@{$info['version']}";

    if (!$force && is_dir($folder)) {
        echo color('green', "$name v{$info['version']} already exists, skipping...\n");
        return;
    }

    echo color('cyan', ($force ? "Updating" : "Downloading") . " $name v{$info['version']}...\n");

    $zipFile = tempnam(sys_get_temp_dir(), 'dep') . '.zip';

    file_put_contents($zipFile, file_get_contents($info['url']));

    $actualHash = hash_file('sha256', $zipFile);
    if (!empty($info['sha256']) && strtolower($actualHash) !== strtolower($info['sha256'])) {
        echo color('red', "Hash mismatch for $name. Expected {$info['sha256']}, got $actualHash\n");
        unlink($zipFile);
        return;
    }

    $zip = new ZipArchive();
    if ($zip->open($zipFile) === true) {
        if ($force && is_dir($folder)) {
            deleteDirectory($folder);
        }
        mkdir($folder, 0777, true);
        $zip->extractTo($folder);
        $zip->close();
        echo color('green', "Extracted to $folder\n");

        $lockData[$name] = [
            'version' => $info['version'],
            'sha256' => $actualHash,
            'git'     => !empty($info['git']),
        ];
    } else {
        echo color('red', "Failed to unzip $name\n");
    }

    unlink($zipFile);
}

function usage(): void
{
    echo "Usage:\n";
    echo "  php cli install <package> <url> [version] [--git] [--force]\n";
    echo "  php cli uninstall <package>\n";
    echo "  php cli install-all [--force]\n";
    exit(1);
}

function parseArgs(array $args)
{
    $cmd = $args[0] ?? null;
    $force = in_array('--force', $args);
    $flags = [
        'force' => $force
    ];

    return [
        'command' => $cmd,
        'arguments' => [],
        'flags' => $flags
    ];
}
