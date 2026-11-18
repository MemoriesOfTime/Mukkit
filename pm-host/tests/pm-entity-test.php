#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Entity acceptance: entities Nukkit has become real PocketMine entities, and a plugin's changes
 * to them go back.
 *
 * The plugin (examples/pm-entity-probe) is ordinary PM code: `/e-report` lists
 * `$world->getEntities()`, `/e-hurt` calls `setHealth()`, `/e-move` calls `teleport()`. No
 * bridge-specific calls and no knowledge of Nukkit's entity ids - the test asserts on what a
 * plugin can actually see.
 *
 * The types are limited by PocketMine 5 itself: its core ships Zombie, Squid and Villager, not
 * the whole Bedrock mob catalogue. An unknown type is reported and skipped, which is what the
 * "unsupported" assertions here pin down.
 *
 * Usage: php pm-host/tests/pm-entity-test.php
 */

require __DIR__ . '/../src/bootstrap.php';

use Mukkit\Host\PmProfile;
use pocketmine\item\StringToItemParser;
use pocketmine\nbt\TreeRoot;
use pocketmine\network\mcpe\protocol\serializer\NetworkNbtSerializer;

$root = dirname(__DIR__, 2);
$pmRoot = getenv('MUKKIT_PM_ROOT') ?: dirname($root) . '/PocketMine-MP';

if (!is_file($pmRoot . '/src/mukkit/polyfill.php') || !is_file($pmRoot . '/vendor/autoload.php')) {
    fwrite(STDERR, "FAIL  fork not usable at {$pmRoot} (need src/mukkit/polyfill.php + vendor/autoload.php)\n");
    exit(1);
}

$base = $root . '/run/pm-entity';
$plugins = $base . '/plugins';
$data = $base . '/data';
$phar = $plugins . '/MukkitEntityProbe.phar';

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
    . ' ' . escapeshellarg($root . '/examples/pm-entity-probe')
    . ' ' . escapeshellarg($phar),
    $buildCode
);
if (($buildCode ?? 1) !== 0) {
    fwrite(STDERR, "FAIL  cannot build MukkitEntityProbe.phar\n");
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

/** Everything the console would print this tick. */
$messages = static function (array $ops): string {
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

/** Entity rows of every entity.state op in a tick, keyed by Nukkit entity id. */
$statesOf = static function (array $ops): array {
    $rows = [];
    foreach ($ops as $op) {
        if (($op['o'] ?? '') !== 'entity.state') {
            continue;
        }
        foreach (($op['e'] ?? []) as $row) {
            $rows[(int) $row[0]] = $row;
        }
    }
    return $rows;
};

$uuid = '11111111-2222-3333-4444-555555555555';
$profile = new PmProfile($pmRoot, $data, $plugins);
$result = $profile->load([['name' => 'MukkitEntityProbe', 'path' => $phar]]);

$names = array_map(static fn (array $p): string => (string) ($p['name'] ?? ''), $result['loaded']);
$check('entity probe phar loaded by PM\'s own PluginManager', in_array('MukkitEntityProbe', $names, true), implode(',', $names));
$check('no rejected plugins', $result['rejected'] === [], var_export($result['rejected'], true));

$command = static function (string $line) use ($profile): array {
    return $profile->tick([['k' => 'command', 'sender' => 'console', 'line' => $line]]);
};

/** Asks the plugin what it sees and returns the reported line. */
$report = static function () use ($command, $messages): string {
    $ops = $command('e-report');
    foreach (explode("\n", $messages($ops)) as $line) {
        $at = strpos($line, 'e-report count=');
        if ($at !== false) {
            return trim(substr($line, $at));
        }
    }
    return '';
};

/** Asks the plugin for the first dropped stack and returns the reported line. */
$itemReport = static function () use ($command, $messages): string {
    $ops = $command('e-item');
    foreach (explode("\n", $messages($ops)) as $line) {
        $at = strpos($line, 'e-item ');
        if ($at !== false) {
            return trim(substr($line, $at));
        }
    }
    return '';
};

$sync = static function (array $rows, string $world = 'world') use ($profile): array {
    return $profile->tick([['k' => 'entity.sync', 'world' => $world, 'e' => $rows]]);
};

// ---------------------------------------------------------------- join
$profile->tick([[
    'k' => 'event',
    'name' => 'player.join',
    'player' => $uuid,
    'data' => ['name' => 'Steve', 'x' => 0.5, 'y' => 64.0, 'z' => 0.5, 'yaw' => 0.0, 'pitch' => 0.0, 'world' => 'world'],
]]);

// ---------------------------------------------------------------- Nukkit -> plugin
$ops = $sync([[700, 'minecraft:zombie', 10.0, 64.0, 10.0, 90.0, 0.0, ['hp' => 20.0]]]);
$check('a synced entity is accepted', !str_contains($messages($ops), 'entity:'), $messages($ops));
$line = $report();
$check(
    'the plugin sees a real zombie with position and health',
    str_contains($line, 'count=1') && str_contains($line, 'type=minecraft:zombie')
        && str_contains($line, 'x=10.00 y=64.00 z=10.00') && str_contains($line, 'hp=20.00'),
    $line
);
$check(
    'the bridged player is not counted as a mirrored entity',
    str_contains($line, 'count=1'),
    $line
);
$idleOps = $profile->tick([]);
$check(
    'a synced entity does not bounce back to Nukkit',
    $statesOf($idleOps) === [],
    (string) json_encode($statesOf($idleOps))
);

// ---------------------------------------------------------------- plugin -> Nukkit
$ops = $command('e-hurt 18');
$states = $statesOf($ops);
$check(
    'setHealth() reaches the bridge for that entity',
    isset($states[700]) && ($states[700][6]['hp'] ?? null) === 18.0,
    (string) json_encode($states)
);
$check(
    'and carries the position with it, not just the health',
    isset($states[700]) && ($states[700][1] ?? null) === 10.0,
    (string) json_encode($states)
);
$idleOps = $profile->tick([]);
$check(
    'the health change is not echoed again',
    $statesOf($idleOps) === [],
    (string) json_encode($statesOf($idleOps))
);

$ops = $command('e-move 12 64 10');
$states = $statesOf($ops);
$check(
    'teleport() reaches the bridge',
    isset($states[700]) && ($states[700][1] ?? null) === 12.0,
    (string) json_encode($states)
);
$check(
    'a move does not re-assert health',
    isset($states[700]) && count($states[700]) === 6,
    (string) json_encode($states)
);

// ---------------------------------------------------------------- Nukkit moves it back
$sync([[700, 'minecraft:zombie', 11.0, 64.0, 10.0, 90.0, 0.0, ['hp' => 18.0]]]);
$line = $report();
$check(
    'a position pushed by Nukkit is what the plugin reads back',
    str_contains($line, 'x=11.00'),
    $line
);
$idleOps = $profile->tick([]);
$check(
    'a mirrored move is not echoed back to Nukkit',
    $statesOf($idleOps) === [],
    (string) json_encode($statesOf($idleOps))
);

// ---------------------------------------------------------------- a second entity
$ops = $sync([
    [700, 'minecraft:zombie', 11.0, 64.0, 10.0, 90.0, 0.0, ['hp' => 18.0]],
    [702, 'minecraft:squid', 1.0, 62.0, 1.0, 0.0, 0.0, ['hp' => 10.0]],
]);
$line = $report();
$check(
    'two entities are both visible to the plugin',
    str_contains($line, 'count=2'),
    $line
);
$check(
    'and the first one is still the zombie',
    str_contains($line, 'type=minecraft:zombie'),
    $line
);

// ---------------------------------------------------------------- name and effects
$ops = $sync([
    [700, 'minecraft:zombie', 11.0, 64.0, 10.0, 90.0, 0.0,
        ['hp' => 18.0, 'name' => 'Bob', 'effects' => [[1, 600, 0, true, false]]]],
    [702, 'minecraft:squid', 1.0, 62.0, 1.0, 0.0, 0.0, ['hp' => 10.0]],
]);
$line = $report();
$check(
    'a name tag pushed by Nukkit is what the plugin reads back',
    str_contains($line, 'name="Bob"'),
    $line
);
$check(
    'an effect pushed by Nukkit is what the plugin reads back',
    str_contains($line, 'effects=1:0:600'),
    $line
);
$idleOps = $profile->tick([]);
$check(
    'a mirrored name and effect do not bounce back to Nukkit',
    $statesOf($idleOps) === [],
    (string) json_encode($statesOf($idleOps))
);

$ops = $command('e-name Alice');
$states = $statesOf($ops);
$check(
    'setNameTag() reaches the bridge for that entity',
    isset($states[700]) && ($states[700][6]['name'] ?? null) === 'Alice',
    (string) json_encode($states)
);
$idleOps = $profile->tick([]);
$check(
    'the name change is not echoed again',
    $statesOf($idleOps) === [],
    (string) json_encode($statesOf($idleOps))
);

$ops = $command('e-effect 2 400 1');
$states = $statesOf($ops);
$check(
    'adding an effect reaches the bridge as one effect tuple',
    isset($states[700]) && ($states[700][6]['effects'] ?? null) === [[1, 600, 0, true, false], [2, 400, 1, true, false]],
    (string) json_encode($states)
);
$idleOps = $profile->tick([]);
$check(
    'the effect change is not echoed again',
    $statesOf($idleOps) === [],
    (string) json_encode($statesOf($idleOps))
);

$ops = $sync([
    [700, 'minecraft:zombie', 11.0, 64.0, 10.0, 90.0, 0.0,
        ['hp' => 18.0, 'name' => 'Bob', 'effects' => []]],
    [702, 'minecraft:squid', 1.0, 62.0, 1.0, 0.0, 0.0, ['hp' => 10.0]],
]);
$line = $report();
$check(
    'an empty effect list clears what the plugin had',
    str_contains($line, 'effects=none'),
    $line
);
$idleOps = $profile->tick([]);
$check(
    'clearing an effect is not echoed back to Nukkit',
    $statesOf($idleOps) === [],
    (string) json_encode($statesOf($idleOps))
);

$ops = $sync([
    [700, 'minecraft:zombie', 11.0, 64.0, 10.0, 90.0, 0.0,
        ['hp' => 18.0, 'effects' => [[200, 400, 0, true, false]]]],
    [702, 'minecraft:squid', 1.0, 62.0, 1.0, 0.0, 0.0, ['hp' => 10.0]],
]);
$check(
    'an effect id PocketMine does not know is reported, not applied',
    str_contains($messages($ops), 'entity 700: unknown effect id 200'),
    $messages($ops)
);
$line = $report();
$check(
    'and it does not appear on the entity',
    str_contains($line, 'effects=none'),
    $line
);

// ---------------------------------------------------------------- a dropped stack
// A dropped item is the inventory dialect one level down: same id/count/damage/NBT, carried in
// the entity's extras map instead of a slot tuple.
$named = StringToItemParser::getInstance()->parse('diamond_sword');
if ($named === null) {
    $check('the test can build a named item', false, 'StringToItemParser refused diamond_sword');
} else {
    $named->setCustomName('Bob');
    $nbt = base64_encode((new NetworkNbtSerializer())->write(new TreeRoot($named->getNamedTag())));
    $ops = $sync([
        [700, 'minecraft:zombie', 11.0, 64.0, 10.0, 90.0, 0.0, ['hp' => 18.0]],
        [704, 'minecraft:item', 3.0, 64.0, 3.0, 0.0, 0.0,
            ['item' => ['minecraft:diamond_sword', 1, 0, $nbt]]],
    ]);
    $line = $report();
    $check('a dropped stack counts as an entity', str_contains($line, 'count=2'), $line);

    $itemLine = $itemReport();
    $check(
        'the stack keeps its item, count and NBT',
        str_contains($itemLine, 'name=Diamond Sword')
            && str_contains($itemLine, 'count=1')
            && str_contains($itemLine, 'custom="Bob"'),
        $itemLine
    );
    $idleOps = $profile->tick([]);
    $check(
        'a dropped stack does not bounce back to Nukkit',
        $statesOf($idleOps) === [],
        (string) json_encode($statesOf($idleOps))
    );

    $ops = $sync([
        [700, 'minecraft:zombie', 11.0, 64.0, 10.0, 90.0, 0.0, ['hp' => 18.0]],
        [705, 'minecraft:item', 4.0, 64.0, 4.0, 0.0, 0.0,
            ['item' => ['minecraft:not_a_real_item', 1, 0]]],
    ]);
    $check(
        'an item name this build does not know is reported, not turned into air',
        str_contains($messages($ops), 'no item named minecraft:not_a_real_item'),
        $messages($ops)
    );
    $line = $report();
    $check('and the unknown stack does not become an entity', str_contains($line, 'count=1'), $line);
    $itemLine = $itemReport();
    $check('the previously mirrored stack is gone', str_contains($itemLine, 'e-item none'), $itemLine);
}

// ---------------------------------------------------------------- removal and refusals
$ops = $sync([]);
$line = $report();
$check(
    'an empty snapshot removes what was there',
    str_contains($line, 'count=0') && !str_contains($messages($ops), 'entity:'),
    $line
);

$ops = $sync([[703, 'minecraft:not_a_mob', 0.0, 64.0, 0.0, 0.0, 0.0, ['hp' => 5.0]]]);
$check(
    'a type PocketMine has no class for is reported, not approximated',
    str_contains($messages($ops), 'unsupported entity type minecraft:not_a_mob'),
    $messages($ops)
);
$line = $report();
$check(
    'and it does not become an entity',
    str_contains($line, 'count=0'),
    $line
);

$ops = $sync([], 'nowhere');
$check(
    'a snapshot for a world nobody joined is refused',
    str_contains($messages($ops), 'no world named nowhere'),
    $messages($ops)
);

// ---------------------------------------------------------------- quit
$profile->tick([['k' => 'event', 'name' => 'player.quit', 'player' => $uuid, 'data' => ['reason' => 'test']]]);
$idleOps = $profile->tick([]);
$check(
    'the host keeps ticking after the player left',
    $statesOf($idleOps) === [],
    (string) json_encode($idleOps)
);

$profile->shutdown();

echo $failures === 0 ? "entity PASS\n" : "entity FAILED ({$failures})\n";
exit($failures === 0 ? 0 : 1);
