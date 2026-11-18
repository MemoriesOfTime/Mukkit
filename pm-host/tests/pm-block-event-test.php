#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Cancellable-event acceptance: a PocketMine plugin can veto a Nukkit action.
 *
 * The plugin (examples/pm-block-probe) is a protection plugin in miniature: it cancels
 * `BlockBreakEvent` inside a box. The frames below are what the adapter sends when a player
 * breaks a block, and the assertion is the verdict that comes back — plus the fact that PocketMine
 * saw a real `Block` (from the mirror) and a real `Item` (from the namespaced id).
 *
 * Usage: php pm-host/tests/pm-block-event-test.php
 */

require __DIR__ . '/../src/bootstrap.php';

use Mukkit\Host\PmProfile;

$root = dirname(__DIR__, 2);
$pmRoot = getenv('MUKKIT_PM_ROOT') ?: dirname($root) . '/PocketMine-MP';

if (!is_file($pmRoot . '/src/mukkit/polyfill.php') || !is_file($pmRoot . '/vendor/autoload.php')) {
    fwrite(STDERR, "FAIL  fork not usable at {$pmRoot} (need src/mukkit/polyfill.php + vendor/autoload.php)\n");
    exit(1);
}

$base = $root . '/run/pm-block-event';
$plugins = $base . '/plugins';
$data = $base . '/data';
$phar = $plugins . '/MukkitBlockProbe.phar';

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
    . ' ' . escapeshellarg($root . '/examples/pm-block-probe')
    . ' ' . escapeshellarg($phar),
    $buildCode
);
if (($buildCode ?? 1) !== 0) {
    fwrite(STDERR, "FAIL  cannot build MukkitBlockProbe.phar\n");
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

$messages = static function (array $ops): string {
    $lines = [];
    foreach ($ops as $op) {
        if (($op['o'] ?? '') === 'packet' && ($op['cls'] ?? '') === 'TextPacket') {
            $lines[] = (string) ($op['f']['message'] ?? '');
        } elseif (($op['o'] ?? '') === 'log') {
            $lines[] = (string) ($op['msg'] ?? '');
        }
    }
    return implode("\n", $lines);
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

/** One section of 4096 blocks: stone at (5, 64, 5). Index is x | z<<4 | y<<8. */
$stoneSection = static function (): array {
    $ids = array_fill(0, 4096, 0);
    $metas = array_fill(0, 4096, 0);
    $ids[5 | (5 << 4)] = 1;
    return [[
        'y' => 4,
        'ids' => base64_encode(gzcompress(pack('v*', ...$ids))),
        'metas' => base64_encode(gzcompress(pack('C*', ...$metas))),
    ]];
};

$uuid = '11111111-2222-3333-4444-555555555555';
$profile = new PmProfile($pmRoot, $data, $plugins);
$result = $profile->load([['name' => 'MukkitBlockProbe', 'path' => $phar]]);

$names = array_map(static fn (array $p): string => (string) ($p['name'] ?? ''), $result['loaded']);
$check('block probe phar loaded by PM\'s own PluginManager', in_array('MukkitBlockProbe', $names, true), implode(',', $names));

$check(
    'a listener makes the host ask for block breaks',
    in_array('block.break', $profile->eventDemand(), true),
    (string) json_encode($profile->eventDemand())
);

// Join + one mirrored chunk, so the event carries a real block rather than air.
$profile->tick([[
    'k' => 'event',
    'name' => 'player.join',
    'player' => $uuid,
    'data' => ['name' => 'Steve', 'x' => 0.5, 'y' => 64.0, 'z' => 0.5, 'yaw' => 0.0, 'pitch' => 0.0, 'world' => 'world'],
]]);
$profile->tick([['k' => 'chunk', 'world' => 'world', 'x' => 0, 'z' => 0, 'sections' => $stoneSection()]]);

$command = static function (string $line) use ($profile): array {
    return $profile->tick([['k' => 'command', 'sender' => 'console', 'line' => $line]]);
};

/** Builds the frame the adapter sends when a player breaks a block. */
$breakFrame = static function (int $id, int $x, int $y, int $z) use ($uuid): array {
    return [
        'k' => 'event',
        'name' => 'block.break',
        'player' => $uuid,
        'id' => $id,
        'data' => [
            'x' => $x,
            'y' => $y,
            'z' => $z,
            'world' => 'world',
            'item' => 'minecraft:diamond_pickaxe',
            'itemCount' => 1,
            'instaBreak' => false,
        ],
    ];
};

$check('the protection box is accepted', str_contains($messages($command('bp-protect 0 60 0 10 70 10')), 'bp-protect done'));

// ---------------------------------------------------------------- denied
$ops = $profile->tick([$breakFrame(1, 5, 64, 5)]);
$text = $messages($ops);
$check('a break inside the protected box is denied', $verdict($ops, 1) === false, (string) json_encode($ops));
$check(
    'the plugin saw the real block from the mirror',
    str_contains($text, 'break at 5,64,5 block=Stone'),
    $text
);
$check(
    'and the real item, rebuilt from the namespaced id',
    str_contains($text, 'item=Diamond Pickaxe x1'),
    $text
);
$check('the plugin reported the cancellation', str_contains($text, 'break denied'), $text);

// ---------------------------------------------------------------- allowed
$ops = $profile->tick([$breakFrame(2, 50, 64, 50)]);
$check('a break outside the box is allowed', $verdict($ops, 2) === true, (string) json_encode($ops));
$check(
    'and the plugin looked at it without cancelling',
    str_contains($messages($ops), 'break at 50,64,50') && !str_contains($messages($ops), 'break denied'),
    $messages($ops)
);

// ---------------------------------------------------------------- refusals
$ops = $profile->tick([[
    'k' => 'event',
    'name' => 'block.break',
    'player' => '99999999-0000-0000-0000-000000000000',
    'id' => 3,
    'data' => ['x' => 1, 'y' => 64, 'z' => 1, 'item' => 'minecraft:stone', 'itemCount' => 1],
]]);
$check('a break for an unknown player is denied, not left hanging', $verdict($ops, 3) === false, (string) json_encode($ops));
$check(
    'and that refusal is reported once',
    str_contains($messages($ops), 'event block.break: no bridged player'),
    $messages($ops)
);

$ops = $profile->tick([[
    'k' => 'event',
    'name' => 'block.break',
    'player' => $uuid,
    'id' => 4,
    'data' => ['item' => 'minecraft:stone'],
]]);
$check('a frame without coordinates is denied rather than guessed', $verdict($ops, 4) === false, (string) json_encode($ops));
$check(
    'and says why',
    str_contains($messages($ops), 'frame could not be turned into a PocketMine event'),
    $messages($ops)
);

$ops = $profile->tick([[
    'k' => 'event',
    'name' => 'block.break',
    'player' => $uuid,
    'data' => ['x' => 5, 'y' => 64, 'z' => 5],
]]);
$check(
    'a frame with no request id produces no verdict',
    $verdict($ops, 0) === null && !str_contains((string) json_encode($ops), 'event.result'),
    (string) json_encode($ops)
);

// ---------------------------------------------------------------- demand follows the plugins
$ops = $profile->tick([['k' => 'plugin', 'name' => 'disable', 'plugin' => 'MukkitBlockProbe']]);
$check(
    'disabling the listener withdraws the demand in the same tick',
    in_array(['o' => 'capabilities', 'events' => []], $ops, true),
    (string) json_encode($ops)
);
$check('and the host reports no demand afterwards', $profile->eventDemand() === [], (string) json_encode($profile->eventDemand()));

$profile->shutdown();

echo $failures === 0 ? "block events PASS\n" : "block events FAILED ({$failures})\n";
exit($failures === 0 ? 0 : 1);
