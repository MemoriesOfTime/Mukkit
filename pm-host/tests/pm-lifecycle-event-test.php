#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Event-surface acceptance: every bridged event reaches a real PocketMine plugin, and the decision
 * tier can veto the Nukkit action.
 *
 * The plugin (examples/pm-event-probe) listens to all of them and cancels one name at a time. The
 * frames below are what the adapter sends; the assertions are the verdicts, the payload the plugin
 * saw, and the refusals that keep a broken frame from hanging.
 *
 * Usage: php pm-host/tests/pm-lifecycle-event-test.php
 */

require __DIR__ . '/../src/bootstrap.php';

use Mukkit\Host\PmProfile;

$root = dirname(__DIR__, 2);
$pmRoot = getenv('MUKKIT_PM_ROOT') ?: dirname($root) . '/PocketMine-MP';

if (!is_file($pmRoot . '/src/mukkit/polyfill.php') || !is_file($pmRoot . '/vendor/autoload.php')) {
    fwrite(STDERR, "FAIL  fork not usable at {$pmRoot} (need src/mukkit/polyfill.php + vendor/autoload.php)\n");
    exit(1);
}

$base = $root . '/run/pm-lifecycle-event';
$plugins = $base . '/plugins';
$data = $base . '/data';
$phar = $plugins . '/MukkitEventProbe.phar';

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
    . ' ' . escapeshellarg($root . '/examples/pm-event-probe')
    . ' ' . escapeshellarg($phar),
    $buildCode
);
if (($buildCode ?? 1) !== 0) {
    fwrite(STDERR, "FAIL  cannot build MukkitEventProbe.phar\n");
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
$result = $profile->load([['name' => 'MukkitEventProbe', 'path' => $phar]]);

$names = array_map(static fn (array $p): string => (string) ($p['name'] ?? ''), $result['loaded']);
$check('event probe phar loaded by PM\'s own PluginManager', in_array('MukkitEventProbe', $names, true), implode(',', $names));

// Every decision the probe listens for, and nothing it does not.
$expected = [
    'player.item.held',
    'player.toggle.sneak', 'player.toggle.sprint', 'player.toggle.fly', 'player.toggle.glide', 'player.toggle.swim',
    'block.grow', 'block.spread', 'block.form', 'block.burn', 'leaves.decay',
];
$demand = $profile->eventDemand();
sort($demand);
$sortedExpected = $expected;
sort($sortedExpected);
$check('one listener asks for all 11 decision events', $demand === $sortedExpected, (string) json_encode($demand));

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

$frame = static function (string $name, int $id, array $data, ?string $player = null): array {
    $item = ['k' => 'event', 'name' => $name, 'data' => $data];
    if ($id > 0) {
        $item['id'] = $id;
    }
    if ($player !== null) {
        $item['player'] = $player;
    }
    return $item;
};

$blockData = static fn (int $x, int $y, int $z, int $id, int $meta): array =>
    ['world' => 'world', 'x' => $x, 'y' => $y, 'z' => $z, 'id' => $id, 'meta' => $meta];

// ---------------------------------------------------------------- block lifecycle
$ops = $profile->tick([$frame('block.grow', 1, $blockData(5, 64, 5, 1, 0) + ['newId' => 59, 'newMeta' => 1])]);
$text = $messages($ops);
$check('a crop growing is allowed by default', $verdict($ops, 1) === true, (string) json_encode($ops));
$check(
    'the plugin saw the block and the state it becomes',
    str_contains($text, 'ev name=block.grow block=Stone')
        && str_contains($text, 'new=Wheat')
        && str_contains($text, 'pos=5,64,5'),
    $text
);

$check('the probe can be told to veto growth', str_contains($messages($command('ev-cancel block.grow on')), 'ev-cancel block.grow=on'));
$ops = $profile->tick([$frame('block.grow', 2, $blockData(5, 64, 5, 1, 0) + ['newId' => 59, 'newMeta' => 2])]);
$check('a vetoed growth is denied', $verdict($ops, 2) === false, (string) json_encode($ops));
$check('and the plugin said so', str_contains($messages($ops), 'ev cancelled block.grow'), $messages($ops));
$command('ev-cancel block.grow off');

$ops = $profile->tick([$frame('block.spread', 3, $blockData(5, 64, 5, 3, 0) + [
    'newId' => 2, 'newMeta' => 0,
    'source' => ['x' => 5, 'y' => 64, 'z' => 4, 'id' => 3, 'meta' => 0],
])]);
$text = $messages($ops);
$check(
    'a spread carries its source block too',
    $verdict($ops, 3) === true
        && str_contains($text, 'ev name=block.spread')
        && str_contains($text, 'source=Dirt'),
    $text
);

$ops = $profile->tick([$frame('block.form', 4, $blockData(1, 60, 1, 0, 0) + ['newId' => 24, 'newMeta' => 0])]);
$check('forming (soul sand -> bubble column) is delivered', $verdict($ops, 4) === true, (string) json_encode($ops));

$ops = $profile->tick([$frame('block.burn', 5, $blockData(0, 64, 0, 5, 0) + ['newId' => 0, 'newMeta' => 0])]);
$check('burning is delivered', $verdict($ops, 5) === true, (string) json_encode($ops));

$ops = $profile->tick([$frame('leaves.decay', 6, $blockData(1, 64, 1, 18, 0))]);
$check('leaves decaying is delivered', $verdict($ops, 6) === true, (string) json_encode($ops));

// ---------------------------------------------------------------- player state events
$ops = $profile->tick([$frame('player.toggle.sneak', 7, ['state' => true], $uuid)]);
$check('a sneak toggle is delivered', $verdict($ops, 7) === true, (string) json_encode($ops));
$componentMessages = $messages($ops);
$check('and carries the state', str_contains($componentMessages, 'ev name=player.toggle.sneak state=true'), $componentMessages);

$command('ev-cancel player.toggle.sneak on');
$ops = $profile->tick([$frame('player.toggle.sneak', 8, ['state' => true], $uuid)]);
$check('a vetoed sneak is denied', $verdict($ops, 8) === false, (string) json_encode($ops));
$command('ev-cancel player.toggle.sneak off');

$ops = $profile->tick([$frame('player.item.held', 9, [
    'slot' => 3,
    'from' => 0,
    'item' => ['minecraft:diamond_sword', 1, 7],
], $uuid)]);
$text = $messages($ops);
$check('a hotbar switch is delivered', $verdict($ops, 9) === true, (string) json_encode($ops));
$check('with the slot and the real item', str_contains($text, 'ev name=player.item.held slot=3 item=Diamond Sword x1'), $text);

$command('ev-cancel player.item.held on');
$ops = $profile->tick([$frame('player.item.held', 10, ['slot' => 4, 'from' => 3], $uuid)]);
$check('a vetoed hotbar switch is denied', $verdict($ops, 10) === false, (string) json_encode($ops));
$command('ev-cancel player.item.held off');

$toggleIds = ['sprint' => 23, 'fly' => 24, 'glide' => 25, 'swim' => 26];
foreach ($toggleIds as $name => $id) {
    $ops = $profile->tick([$frame('player.toggle.' . $name, $id, ['state' => false], $uuid)]);
    $check(
        'toggle ' . $name . ' is delivered',
        $verdict($ops, $id) === true
            && str_contains($messages($ops), 'ev name=player.toggle.' . $name . ' state=false'),
        $messages($ops)
    );
}

// ---------------------------------------------------------------- observation tier
$ops = $profile->tick([$frame('player.respawn', 0, [
    'x' => 0.5, 'y' => 65.0, 'z' => 0.5, 'yaw' => 0.0, 'pitch' => 0.0, 'world' => 'world', 'firstSpawn' => false,
], $uuid)]);
$text = $messages($ops);
$check('a respawn is delivered as news', str_contains($text, 'ev name=player.respawn pos=0,65,0 world=world'), $text);
$check('and needs no verdict', !str_contains((string) json_encode($ops), 'event.result'), (string) json_encode($ops));

$ops = $profile->tick([$frame('player.death', 0, [
    'drops' => [['minecraft:diamond', 2, 0], ['minecraft:apple', 1, 0]],
    'xp' => 7,
    'message' => 'Steve died',
], $uuid)]);
$text = $messages($ops);
$check(
    'a death is delivered with drops, xp and message',
    str_contains($text, 'ev name=player.death message=Steve died xp=7 drops=Diamondx2,Applex1'),
    $text
);
$check('and needs no verdict either', !str_contains((string) json_encode($ops), 'event.result'), (string) json_encode($ops));

// ---------------------------------------------------------------- refusals
$ops = $profile->tick([$frame('block.grow', 11, array_replace(
    $blockData(5, 64, 5, 1, 0) + ['newId' => 59],
    ['world' => 'nowhere']
))]);
$check('a block event for an unmirrored world is denied, not guessed', $verdict($ops, 11) === false, (string) json_encode($ops));
$check('and says why', str_contains($messages($ops), 'frame could not be turned into a PocketMine event'), $messages($ops));

$ops = $profile->tick([$frame('block.grow', 12, $blockData(5, 64, 5, 1, 0) + ['newId' => 9999])]);
$check('an unknown legacy block id is denied', $verdict($ops, 12) === false, (string) json_encode($ops));

$ops = $profile->tick([$frame('block.grow', 13, $blockData(5, 64, 5, 1, 0) + ['newId' => 59])]);
$check('a world event needs no player uuid', $verdict($ops, 13) === true, (string) json_encode($ops));

$ops = $profile->tick([$frame('player.toggle.fly', 14, ['state' => 'yes'], $uuid)]);
$check('a toggle with a non-boolean state is denied rather than coerced', $verdict($ops, 14) === false, (string) json_encode($ops));

$ops = $profile->tick([$frame('player.toggle.fly', 15, ['state' => true], '99999999-0000-0000-0000-000000000000')]);
$check('a toggle for an unknown player is denied, not left hanging', $verdict($ops, 15) === false, (string) json_encode($ops));

// ---------------------------------------------------------------- demand follows the plugins
$ops = $profile->tick([['k' => 'plugin', 'name' => 'disable', 'plugin' => 'MukkitEventProbe']]);
$check(
    'disabling the listener withdraws every demand in the same tick',
    in_array(['o' => 'capabilities', 'events' => []], $ops, true),
    (string) json_encode($ops)
);
$check('and the host reports no demand afterwards', $profile->eventDemand() === [], (string) json_encode($profile->eventDemand()));

$profile->shutdown();

echo $failures === 0 ? "lifecycle events PASS\n" : "lifecycle events FAILED ({$failures})\n";
exit($failures === 0 ? 0 : 1);
