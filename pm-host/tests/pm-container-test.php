#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Container acceptance: a PocketMine plugin's chest becomes a Nukkit window, its content travels,
 * and a client transaction can be allowed or vetoed.
 *
 * The plugin (examples/pm-container-probe) is ordinary PM code: it builds a `ChestInventory`,
 * calls `setCurrentWindow()`, and listens to `InventoryTransactionEvent`. The frames below are
 * what the adapter and the client would send; the assertions are the window the adapter is told
 * to open, the content that reaches it, and the verdict plus resulting content after a move.
 *
 * Usage: php pm-host/tests/pm-container-test.php
 */

require __DIR__ . '/../src/bootstrap.php';

use Mukkit\Host\PmProfile;

$root = dirname(__DIR__, 2);
$pmRoot = getenv('MUKKIT_PM_ROOT') ?: dirname($root) . '/PocketMine-MP';

if (!is_file($pmRoot . '/src/mukkit/polyfill.php') || !is_file($pmRoot . '/vendor/autoload.php')) {
    fwrite(STDERR, "FAIL  fork not usable at {$pmRoot} (need src/mukkit/polyfill.php + vendor/autoload.php)\n");
    exit(1);
}

$base = $root . '/run/pm-container';
$plugins = $base . '/plugins';
$data = $base . '/data';
$phar = $plugins . '/MukkitContainerProbe.phar';

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
    . ' ' . escapeshellarg($root . '/examples/pm-container-probe')
    . ' ' . escapeshellarg($phar),
    $buildCode
);
if (($buildCode ?? 1) !== 0) {
    fwrite(STDERR, "FAIL  cannot build MukkitContainerProbe.phar\n");
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

/** Merged window content from content/slot ops this tick: slot => tuple. */
$windowSlots = static function (array $ops): array {
    $slots = [];
    foreach ($ops as $op) {
        if (($op['o'] ?? '') === 'window.content') {
            foreach (($op['slots'] ?? []) as $tuple) {
                $slots[(int) $tuple[0]] = $tuple;
            }
        } elseif (($op['o'] ?? '') === 'window.slot' && is_array($op['slot'] ?? null)) {
            $slots[(int) $op['slot'][0]] = $op['slot'];
        }
    }
    return $slots;
};

/** The verdict for one request id, or null when the host sent none. */
$verdict = static function (array $ops, int $id): ?bool {
    foreach ($ops as $op) {
        if (($op['o'] ?? '') === 'event.result' && (int) ($op['id'] ?? -1) === $id) {
            return (bool) ($op['allowed'] ?? false);
        }
    }
    return null;
};

/** Merged player inventory slots this tick: slot => tuple. */
$playerSlots = static function (array $ops): array {
    $slots = [];
    foreach ($ops as $op) {
        if (($op['o'] ?? '') !== 'player.inventory') {
            continue;
        }
        foreach (($op['slots'] ?? []) as $tuple) {
            $slots[(int) $tuple[0]] = $tuple;
        }
    }
    return $slots;
};

$uuid = '11111111-2222-3333-4444-555555555555';
$profile = new PmProfile($pmRoot, $data, $plugins);
$result = $profile->load([['name' => 'MukkitContainerProbe', 'path' => $phar]]);

$names = array_map(static fn (array $p): string => (string) ($p['name'] ?? ''), $result['loaded']);
$check('container probe phar loaded by PM\'s own PluginManager', in_array('MukkitContainerProbe', $names, true), implode(',', $names));
$check('no rejected plugins', $result['rejected'] === [], var_export($result['rejected'], true));

$demand = $profile->eventDemand();
$check('the plugin asks for inventory.transaction and nothing else', $demand === ['inventory.transaction'], (string) json_encode($demand));

$command = static function (string $line) use ($profile): array {
    return $profile->tick([['k' => 'command', 'sender' => 'console', 'line' => $line]]);
};

$profile->tick([[
    'k' => 'event',
    'name' => 'player.join',
    'player' => $uuid,
    'data' => ['name' => 'Steve', 'x' => 0.5, 'y' => 64.0, 'z' => 0.5, 'yaw' => 0.0, 'pitch' => 0.0, 'world' => 'world'],
]]);

// ---------------------------------------------------------------- open
$ops = $command('ct-open');
$opens = $ofKind($ops, 'window.open');
$check('setCurrentWindow() produces exactly one window.open', count($opens) === 1, (string) count($opens));
$open = $opens[0] ?? [];
$check('the window id is PocketMine\'s own', ($open['window'] ?? null) === 2, (string) json_encode($open));
$check('the window type is a chest', ($open['type'] ?? null) === 0, (string) json_encode($open));
$check(
    'the window is anchored at the chest position',
    ($open['x'] ?? null) === 0 && ($open['y'] ?? null) === 64 && ($open['z'] ?? null) === 0,
    (string) json_encode($open)
);

$content = $windowSlots($ops);
$check('the chest content reaches the adapter', ($content[0] ?? null) === [0, 'minecraft:diamond', 5, 0], (string) json_encode($content));
$check('the second stack reaches the adapter', ($content[1] ?? null) === [1, 'minecraft:apple', 3, 0], (string) json_encode($content));
$check('the snapshot names its size', ($ofKind($ops, 'window.content')[0]['size'] ?? null) === 27, (string) json_encode($ofKind($ops, 'window.content')));

$report = $log($command('ct-report'));
$check('the plugin sees the same contents', str_contains($report, 'slots=0=Diamondx5,1=Applex3'), $report);

// ---------------------------------------------------------------- a move the plugin allows
$ops = $profile->tick([[
    'k' => 'event',
    'name' => 'inventory.transaction',
    'player' => $uuid,
    'id' => 1,
    'data' => [
        'window' => 2,
        'actions' => [
            ['inv' => 'window', 'slot' => 0, 'from' => ['minecraft:diamond', 5, 0], 'to' => ['minecraft:air', 0, 0]],
            ['inv' => 'player', 'slot' => 0, 'from' => ['minecraft:air', 0, 0], 'to' => ['minecraft:diamond', 5, 0]],
        ],
    ],
]]);
$check('the plugin is asked before the move', str_contains($log($ops), 'ct-event'), $log($ops));
$check('the plugin\'s verdict is allowed', $verdict($ops, 1) === true, var_export($verdict($ops, 1), true));

$changed = $windowSlots($ops);
$check('the chest slot is emptied for the adapter', ($changed[0] ?? null) === [0, 'minecraft:air', 0, 0], (string) json_encode($changed));
$playerChanged = $playerSlots($ops);
$check('the diamond reaches the player inventory', ($playerChanged[0] ?? null) === [0, 'minecraft:diamond', 5, 0], (string) json_encode($playerChanged));

$report = $log($command('ct-report'));
$check('the plugin\'s chest no longer has the diamond', !str_contains($report, '0=Diamondx5'), $report);
$check('the apple is still there', str_contains($report, '1=Applex3'), $report);

// ---------------------------------------------------------------- a move the plugin vetoes
$command('ct-cancel on');
$ops = $profile->tick([[
    'k' => 'event',
    'name' => 'inventory.transaction',
    'player' => $uuid,
    'id' => 2,
    'data' => [
        'window' => 2,
        'actions' => [
            ['inv' => 'window', 'slot' => 1, 'from' => ['minecraft:apple', 3, 0], 'to' => ['minecraft:air', 0, 0]],
            ['inv' => 'player', 'slot' => 1, 'from' => ['minecraft:air', 0, 0], 'to' => ['minecraft:apple', 3, 0]],
        ],
    ],
]]);
$check('a vetoed move reports allowed=false', $verdict($ops, 2) === false, var_export($verdict($ops, 2), true));
$check('no window content is sent for a vetoed move', $windowSlots($ops) === [], (string) json_encode($windowSlots($ops)));
$check('no player inventory correction is sent for a vetoed move', $playerSlots($ops) === [], (string) json_encode($playerSlots($ops)));

$report = $log($command('ct-report'));
$check('the vetoed apple stays in the chest', str_contains($report, '1=Applex3'), $report);

// ---------------------------------------------------------------- a frame for a window nobody has
$ops = $profile->tick([[
    'k' => 'event',
    'name' => 'inventory.transaction',
    'player' => $uuid,
    'id' => 3,
    'data' => [
        'window' => 99,
        'actions' => [
            ['inv' => 'window', 'slot' => 0, 'from' => ['minecraft:air', 0, 0], 'to' => ['minecraft:stone', 1, 0]],
        ],
    ],
]]);
$check('a transaction for a stale window is refused', $verdict($ops, 3) === false, var_export($verdict($ops, 3), true));

// ---------------------------------------------------------------- a plugin write while open
$ops = $command('ct-set 2 emerald 4');
$slots = $windowSlots($ops);
$check('setItem() on the open window becomes a window.slot', ($slots[2] ?? null) === [2, 'minecraft:emerald', 4, 0], (string) json_encode($slots));

// ---------------------------------------------------------------- close
$ops = $command('ct-close');
$closes = $ofKind($ops, 'window.close');
$check('removeCurrentWindow() produces window.close', count($closes) === 1 && ($closes[0]['window'] ?? null) === 2, (string) json_encode($closes));

$profile->shutdown();

echo "\n";
if ($failures > 0) {
    echo "container FAIL  ({$failures} failing)\n";
    exit(1);
}
echo "container PASS\n";
