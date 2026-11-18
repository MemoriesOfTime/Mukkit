#!/usr/bin/env php
<?php

declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

use Mukkit\Host\PluginYml;

$path = $argv[1] ?? null;
if (!is_string($path) || $path === '') {
    fwrite(STDERR, "usage: pm-inspect.php <path-to-phar>\n");
    exit(2);
}

if (!is_file($path)) {
    fwrite(STDERR, "pm-inspect: {$path} not found\n");
    exit(2);
}

try {
    $manifest = PluginYml::fromPhar($path);
} catch (Throwable $e) {
    fwrite(STDERR, 'pm-inspect: cannot read plugin.yml: ' . $e->getMessage() . "\n");
    exit(2);
}

$name = trim((string) ($manifest['name'] ?? ''));
$main = trim((string) ($manifest['main'] ?? ''));
if ($name === '' || $main === '') {
    fwrite(STDERR, "pm-inspect: {$path} has no usable plugin.yml (name/main missing)\n");
    exit(2);
}

$api = $manifest['api'] ?? [];
if (is_array($api)) {
    $api = array_values(array_map(static fn (mixed $v): string => (string) $v, $api));
} elseif (is_string($api) && $api !== '') {
    $api = [$api];
} else {
    $api = [];
}

$json = json_encode([
    'name' => $name,
    'main' => $main,
    'version' => (string) ($manifest['version'] ?? ''),
    'api' => $api,
    'description' => (string) ($manifest['description'] ?? ''),
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

if ($json === false) {
    fwrite(STDERR, 'pm-inspect: cannot encode result: ' . json_last_error_msg() . "\n");
    exit(2);
}

fwrite(STDOUT, $json . "\n");
exit(0);
