#!/usr/bin/env php
<?php

declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

use Mukkit\Host\Capability;

$audit = Capability::audit();

printf("%-15s %s\n", 'php', $audit['php']);
printf("%-15s %s\n", 'pm source', $audit['pm_source'] ?? 'not found');
printf("%-15s %s\n", 'pm version', $audit['pm_version'] ?? '-');
printf(
    "%-15s %s\n",
    'headless deps',
    $audit['missing_headless'] === [] ? 'ok' : 'missing: ' . implode(', ', $audit['missing_headless'])
);
printf(
    "%-15s %s\n",
    'upstream deps',
    $audit['missing'] === [] ? 'ok' : 'missing: ' . implode(', ', $audit['missing'])
);
printf(
    "%-15s %s\n",
    'verdict',
    $audit['ready']
        ? 'real PocketMine boot is possible'
        : 'real PocketMine boot is NOT possible, stub profile only'
);

echo "\n";
echo "profile=folder names checked:\n";
foreach (['pmmpthread', 'leveldb', 'chunkutils2'] as $extension) {
    printf("  %-14s %s\n", $extension, extension_loaded($extension) ? 'loaded' : 'absent');
}
