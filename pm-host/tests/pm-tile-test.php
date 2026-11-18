#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Block-entity acceptance: a chunk snapshot's tiles become real PocketMine tiles, the host sees
 * what Nukkit put in them, and a plugin's own writes come back as a `tile` op.
 *
 * The frames below are what the adapter would send (a chunk with a chest and a sign, then an
 * incremental tile update and a removal). The plugin is ordinary PM code and only ever calls
 * `$world->getTileAt()` / `getInventory()` / `setText()`; no bridge call appears in it.
 *
 * Usage: php pm-host/tests/pm-tile-test.php
 */

require __DIR__ . '/../src/bootstrap.php';

use Mukkit\Host\PmProfile;

$root = dirname(__DIR__, 2);
$pmRoot = getenv('MUKKIT_PM_ROOT') ?: dirname($root) . '/PocketMine-MP';

if (!is_file($pmRoot . '/src/mukkit/polyfill.php') || !is_file($pmRoot . '/vendor/autoload.php')) {
    fwrite(STDERR, "FAIL  fork not usable at {$pmRoot} (need src/mukkit/polyfill.php + vendor/autoload.php)\n");
    exit(1);
}

$base = $root . '/run/pm-tile';
$plugins = $base . '/plugins';
$data = $base . '/data';
$phar = $plugins . '/MukkitTileProbe.phar';

$rmrf = static function (string $path) use (&$rmrf): void {
    if (is_dir($path) && !is_link($path)) {
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                $rmrf($path . '/' . $entry);
            }
        }
        @rmdir($path);
    } elseif (file_exists($path) || is_link($path)) {
        @unlink($path);
    }
};
$rmrf($base);
mkdir($plugins, 0777, true);
mkdir($data, 0777, true);

passthru(
    'bash ' . escapeshellarg($root . '/scripts/build-plugin-phar.sh')
    . ' ' . escapeshellarg($root . '/examples/pm-tile-probe')
    . ' ' . escapeshellarg($phar),
    $buildCode
);
if (($buildCode ?? 1) !== 0) {
    fwrite(STDERR, "FAIL  cannot build MukkitTileProbe.phar\n");
    exit(1);
}

$failures = 0;
$check = static function (string $label, bool $ok, string $detail = '') use (&$failures): void {
    if ($ok) {
        echo "ok    {$label}\n";
        return;
    }
    echo "FAIL  {$label}" . ($detail === '' ? '' : " ({$detail})") . "\n";
    $failures++;
};

/** Every log line this tick, plugin and host alike. */
$log = static function (array $ops): string {
    $lines = [];
    foreach ($ops as $op) {
        if (($op['o'] ?? '') === 'log') {
            $lines[] = (string) ($op['msg'] ?? '');
        } elseif (($op['o'] ?? '') === 'packet' && ($op['cls'] ?? '') === 'TextPacket') {
            $lines[] = (string) ($op['f']['message'] ?? '');
        }
    }
    return implode("\n", $lines);
};

/** Ops of one kind. */
$ofKind = static function (array $ops, string $kind): array {
    return array_values(array_filter($ops, static fn (array $op): bool => ($op['o'] ?? '') === $kind));
};

/** Merged tile entries from every tile op this tick, keyed "x:y:z". */
$tileMap = static function (array $ops): array {
    $out = [];
    foreach ($ops as $op) {
        if (($op['o'] ?? '') !== 'tile') {
            continue;
        }
        foreach (($op['tiles'] ?? []) as $tile) {
            $out[($tile['x'] ?? '?') . ':' . ($tile['y'] ?? '?') . ':' . ($tile['z'] ?? '?')] = $tile;
        }
    }
    return $out;
};

$uuid = '11111111-2222-3333-4444-555555555555';
$profile = new PmProfile($pmRoot, $data, $plugins);
$result = $profile->load([['name' => 'MukkitTileProbe', 'path' => $phar]]);

$names = array_map(static fn (array $p): string => (string) ($p['name'] ?? ''), $result['loaded']);
$check('tile probe phar loaded by PM\'s own PluginManager', in_array('MukkitTileProbe', $names, true), implode(',', $names));
$check('no rejected plugins', $result['rejected'] === [], var_export($result['rejected'], true));

$command = static function (string $line) use ($profile): array {
    return $profile->tick([['k' => 'command', 'sender' => 'console', 'line' => $line]]);
};

$profile->tick([[
    'k' => 'event',
    'name' => 'player.join',
    'player' => $uuid,
    'data' => ['name' => 'Steve', 'x' => 0.5, 'y' => 64.0, 'z' => 0.5, 'yaw' => 0.0, 'pitch' => 0.0, 'world' => 'world'],
]]);

// ---------------------------------------------------------------- the chunk frame carries its tiles
$ops = $profile->tick([[
    'k' => 'chunk',
    'world' => 'world',
    'x' => 0,
    'z' => 0,
    'sections' => [],
    'tiles' => [
        [
            'x' => 10, 'y' => 64, 'z' => 10, 'type' => 'Chest',
            'items' => [[0, 'minecraft:diamond', 5, 0], [1, 'minecraft:apple', 3, 0]],
        ],
        [
            'x' => 12, 'y' => 64, 'z' => 10, 'type' => 'Sign',
            'front' => ['hello', 'world', '', ''],
            'frontColor' => -16777216, 'frontGlow' => false,
            'back' => ['', '', '', ''], 'backColor' => -16777216, 'backGlow' => false,
        ],
        [
            'x' => 14, 'y' => 64, 'z' => 10, 'type' => 'Furnace',
            'items' => [[0, 'minecraft:iron_ore', 2, 0]],
            'burn' => 120, 'cook' => 40, 'max' => 200,
        ],
    ],
]]);
$check('creating tiles emits no tile op of its own', $ofKind($ops, 'tile') === [], (string) json_encode($ofKind($ops, 'tile')));

$report = $log($command('tl-report 10 64 10'));
$check('getTile() returns a Chest', str_contains($report, 'type=Chest'), $report);
$check('the chest holds the items Nukkit sent', str_contains($report, '0=diamond:5,1=apple:3'), $report);

$report = $log($command('tl-report 12 64 10'));
$check('getTile() returns a Sign', str_contains($report, 'type=Sign'), $report);
$check('the sign text is readable', str_contains($report, 'front=hello|world||'), $report);
$check('the sign colour is the one Nukkit sent', str_contains($report, 'frontColor=4278190080'), $report);

$report = $log($command('tl-report 14 64 10'));
$check('getTile() returns a Furnace', str_contains($report, 'type=NormalFurnace'), $report);
$check('furnace progress survives the trip', str_contains($report, 'burn=120 cook=40 max=200'), $report);

// ---------------------------------------------------------------- incremental update
$ops = $profile->tick([[
    'k' => 'tile',
    'world' => 'world',
    'tiles' => [[
        'x' => 10, 'y' => 64, 'z' => 10, 'type' => 'Chest',
        'items' => [[0, 'minecraft:diamond', 7, 0], [1, 'minecraft:apple', 3, 0]],
    ]],
]]);
$check('an inbound tile update is not echoed back', $ofKind($ops, 'tile') === [], (string) json_encode($ofKind($ops, 'tile')));
$report = $log($command('tl-report 10 64 10'));
$check('the new count is visible', str_contains($report, '0=diamond:7'), $report);

// ---------------------------------------------------------------- a plugin writes a slot
$ops = $command('tl-set 10 64 10 2 emerald 4');
$tiles = $tileMap($ops);
$check('setItem() on a tile becomes one tile op', isset($tiles['10:64:10']), (string) json_encode($ops));
$chest = $tiles['10:64:10'] ?? [];
$check('the tile op names the chest type', ($chest['type'] ?? null) === 'Chest', (string) json_encode($chest));
$check(
    'the tile op carries the new slot and the old ones',
    ($chest['items'] ?? null) === [
        [0, 'minecraft:diamond', 7, 0],
        [1, 'minecraft:apple', 3, 0],
        [2, 'minecraft:emerald', 4, 0],
    ],
    (string) json_encode($chest['items'] ?? null)
);

// ---------------------------------------------------------------- a plugin writes sign text
$ops = $command('tl-sign 12 64 10 changed');
$tiles = $tileMap($ops);
$check('setText() on a sign becomes one tile op', isset($tiles['12:64:10']), (string) json_encode($ops));
$sign = $tiles['12:64:10'] ?? [];
$check(
    'the tile op carries the new lines',
    ($sign['front'] ?? null) === ['changed', 'world', '', ''],
    (string) json_encode($sign['front'] ?? null)
);

// ---------------------------------------------------------------- opening a tile's inventory
$ops = $command('tl-open 10 64 10');
$opens = $ofKind($ops, 'window.open');
$check('setCurrentWindow() on a tile produces one window.open', count($opens) === 1, (string) count($opens));
$open = $opens[0] ?? [];
$check(
    'the window is anchored at the chest',
    ($open['x'] ?? null) === 10 && ($open['y'] ?? null) === 64 && ($open['z'] ?? null) === 10,
    (string) json_encode($open)
);
$check('the window is a chest window', ($open['type'] ?? null) === 0, (string) json_encode($open));
$check('the window opens at 27 slots', ($ofKind($ops, 'window.content')[0]['size'] ?? null) === 27, (string) json_encode($ofKind($ops, 'window.content')));
$command('tl-close');

// ---------------------------------------------------------------- removal
$ops = $profile->tick([[
    'k' => 'tile',
    'world' => 'world',
    'removed' => [[12, 64, 10]],
]]);
$report = $log($command('tl-report 12 64 10'));
$check('a removed tile leaves getTile() returning null', str_contains($report, 'tl-report none'), $report);

// ---------------------------------------------------------------- untouched tiles stay quiet
$ops = $command('tl-report 10 64 10');
$check('reading a tile produces no tile op', $ofKind($ops, 'tile') === [], (string) json_encode($ofKind($ops, 'tile')));

$profile->shutdown();

echo "\n";
if ($failures > 0) {
    echo "tile FAIL  ({$failures} failing)\n";
    exit(1);
}
echo "tile PASS\n";
