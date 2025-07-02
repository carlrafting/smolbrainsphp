<?php

$lockFile = __DIR__ . '/deps.lock';
$lockData = file_exists($lockFile) ? json_decode(file_get_contents($lockFile), true) : [];

spl_autoload_register(function ($class) use ($lockData) {
    foreach ($lockData as $name => $meta) {
        $prefix = ''; // Optional: load from extended deps.json if needed
        $vendor = str_replace('/', '-', $name);
        $baseDir = __DIR__ . "/vendor/{$vendor}@{$meta['version']}/src/";

        $prefixParts = explode('/', $name);
        $prefix = ucfirst(end($prefixParts)) . '\\';

        if (str_starts_with($class, $prefix)) {
            $relativeClass = substr($class, strlen($prefix));
            $path = $baseDir . str_replace('\\', '/', $relativeClass) . '.php';
            if (file_exists($path)) {
                require $path;
                return;
            }
        }
    }
});
