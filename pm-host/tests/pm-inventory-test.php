#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Inventory acceptance: what a plugin puts in a slot reaches Nukkit, and what Nukkit's player
 * carries reaches the plugin.
 *
 * The plugin (examples/pm-inventory-probe) is ordinary PM code: `/inv-set`, `/inv-armor` and
 * `/inv-offhand` call `getInventory()->setItem()`, `getArmorInventory()->setHelmet()` and
 * `getOffHandInventory()->setItem()`. `/inv-report` reads the same slots back, which is how the
 * inbound direction is asserted.
 *
 * Slot numbers on the wire are the bridge's: 0-35 inventory, 36-39 armour, 40 offhand. The
 * plugin never uses that numbering - it uses PM's own objects - so a passing test means the
 * mapping between the two numberings is right, not that both sides were written by the same
 * person with the same assumption.
 *
 * Usage: php pm-host/tests/pm-inventory-test.php
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

$base = $root . '/run/pm-inventory';
$plugins = $base . '/plugins';
$data = $base . '/data';
$phar = $plugins . '/MukkitInventoryProbe.phar';

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
    . ' ' . escapeshellarg($root . '/examples/pm-inventory-probe')
    . ' ' . escapeshellarg($phar),
    $buildCode
);
if (($buildCode ?? 1) !== 0) {
    fwrite(STDERR, "FAIL  cannot build MukkitInventoryProbe.phar\n");
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

/** Merged slot map of every player.inventory op in a tick: slot => tuple. */
$slotsOf = static function (array $ops): array {
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
$result = $profile->load([['name' => 'MukkitInventoryProbe', 'path' => $phar]]);

$names = array_map(static fn (array $p): string => (string) ($p['name'] ?? ''), $result['loaded']);
$check('inventory probe phar loaded by PM\'s own PluginManager', in_array('MukkitInventoryProbe', $names, true), implode(',', $names));
$check('no rejected plugins', $result['rejected'] === [], var_export($result['rejected'], true));

/** Runs one console command and returns the ops of that tick. */
$command = static function (string $line) use ($profile): array {
    return $profile->tick([['k' => 'command', 'sender' => 'console', 'line' => $line]]);
};

/** Asks the plugin what it thinks the inventory holds, and returns the reported line. */
$report = static function () use ($command, $messages): string {
    $ops = $command('inv-report');
    foreach (explode("\n", $messages($ops)) as $line) {
        //Console output arrives with PocketMine's own "Command output |" prefix.
        $at = strpos($line, 'inv-report 0=');
        if ($at !== false) {
            return trim(substr($line, $at));
        }
    }
    return '';
};

/** One idle tick, so "nothing happens" can be asserted without spending two ticks. */
$idle = static fn (): array => $profile->tick([]);

// ---------------------------------------------------------------- join
$joinOps = $profile->tick([[
    'k' => 'event',
    'name' => 'player.join',
    'player' => $uuid,
    'data' => ['name' => 'Steve', 'x' => 0.5, 'y' => 64.0, 'z' => 0.5, 'yaw' => 0.0, 'pitch' => 0.0, 'world' => 'world'],
]]);
$check(
    'an empty inventory produces no player.inventory op on join',
    $slotsOf($joinOps) === [],
    (string) json_encode($slotsOf($joinOps))
);
$idleOps = $idle();
$check(
    'an idle tick produces no player.inventory op',
    $slotsOf($idleOps) === [],
    (string) json_encode($slotsOf($idleOps))
);

// ---------------------------------------------------------------- outbound: PM -> Nukkit

$ops = $command('inv-set 0 diamond 3');
$slots = $slotsOf($ops);
$check(
    'setItem() reaches the bridge as slot 0',
    isset($slots[0]) && $slots[0][1] === 'minecraft:diamond' && $slots[0][2] === 3,
    (string) json_encode($slots)
);
$check(
    'the item does not drag other slots along',
    array_keys($slots) === [0],
    (string) json_encode(array_keys($slots))
);
$idleOps = $idle();
$check(
    'a mirror write is not echoed back on the next tick',
    $slotsOf($idleOps) === [],
    (string) json_encode($slotsOf($idleOps))
);

// Changed count only: still the same slot, and the tuple carries the new stack size.
$ops = $command('inv-set 0 diamond 7');
$slots = $slotsOf($ops);
$check(
    'a stack-size change is a change',
    isset($slots[0]) && $slots[0][2] === 7,
    (string) json_encode($slots)
);

// ---------------------------------------------------------------- NBT
$ops = $command('inv-set 1 diamond_sword 1 Bob');
$slots = $slotsOf($ops);
$check(
    'a custom name travels as a fifth NBT element',
    isset($slots[1]) && count($slots[1]) === 5,
    (string) json_encode($slots[1] ?? null)
);
$decoded = isset($slots[1][4]) ? base64_decode((string) $slots[1][4], true) : false;
$check(
    'the NBT blob is network little-endian and carries the name',
    is_string($decoded) && str_contains($decoded, 'Bob'),
    $decoded === false ? 'not base64' : bin2hex($decoded)
);

// ---------------------------------------------------------------- armour and offhand
$ops = $command('inv-armor head iron_helmet');
$slots = $slotsOf($ops);
$check(
    'getArmorInventory() maps to slot 36',
    isset($slots[36]) && $slots[36][1] === 'minecraft:iron_helmet',
    (string) json_encode($slots)
);

$ops = $command('inv-offhand bow');
$slots = $slotsOf($ops);
$check(
    'getOffHandInventory() maps to slot 40',
    isset($slots[40]) && $slots[40][1] === 'minecraft:bow',
    (string) json_encode($slots)
);

$ops = $command('inv-clear');
$slots = $slotsOf($ops);
$check(
    'clearing sends an empty tuple per changed slot',
    isset($slots[0], $slots[1], $slots[36], $slots[40])
        && $slots[0][1] === 'minecraft:air' && $slots[0][2] === 0,
    (string) json_encode($slots)
);

// ---------------------------------------------------------------- inbound: Nukkit -> PM
$profile->tick([[
    'k' => 'player.inventory',
    'player' => $uuid,
    'slots' => [[2, 'minecraft:emerald', 5, 0]],
]]);
$line = $report();
$check(
    'a slot pushed by Nukkit is what the plugin reads back',
    str_contains($line, '2=Emerald x5'),
    $line
);
$idleOps = $idle();
$check(
    'mirrored slots are not echoed back to Nukkit',
    $slotsOf($idleOps) === [],
    (string) json_encode($slotsOf($idleOps))
);

$named = StringToItemParser::getInstance()->parse('diamond_sword');
if ($named === null) {
    $check('the test can build a named item', false, 'StringToItemParser refused diamond_sword');
} else {
    $named->setCustomName('Alice');
    $nbt = base64_encode((new NetworkNbtSerializer())->write(new TreeRoot($named->getNamedTag())));
    $profile->tick([[
        'k' => 'player.inventory',
        'player' => $uuid,
        'slots' => [[3, 'minecraft:diamond_sword', 1, 0, $nbt]],
    ]]);
    $line = $report();
    $check(
        'NBT pushed by Nukkit is applied to the item',
        str_contains($line, '3=Diamond Sword x1 dmg=0 "Alice"'),
        $line
    );
}

$profile->tick([[
    'k' => 'player.inventory',
    'player' => $uuid,
    'slots' => [[36, 'minecraft:golden_helmet', 1, 0]],
]]);
$line = $report();
$check(
    'an armour slot pushed by Nukkit is applied',
    str_contains($line, 'head=Golden Helmet x1'),
    $line
);

// Durability travels in meta, and the damage a plugin or the server applied must not bounce.
$profile->tick([[
    'k' => 'player.inventory',
    'player' => $uuid,
    'slots' => [[4, 'minecraft:diamond_pickaxe', 1, 37]],
]]);
$line = $report();
$check(
    'durability damage is applied to the item',
    str_contains($line, '4=Diamond Pickaxe x1 dmg=37'),
    $line
);
$idleOps = $idle();
$check(
    'and is not re-sent on the next tick',
    $slotsOf($idleOps) === [],
    (string) json_encode($slotsOf($idleOps))
);

// ---------------------------------------------------------------- refusals
$ops = $profile->tick([[
    'k' => 'player.inventory',
    'player' => $uuid,
    'slots' => [[0, 'minecraft:not_a_real_item', 1, 0]],
]]);
$check(
    'an item name this build does not know is reported, not guessed',
    str_contains($messages($ops), 'no item named minecraft:not_a_real_item'),
    $messages($ops)
);

$ops = $profile->tick([[
    'k' => 'player.inventory',
    'player' => $uuid,
    'slots' => [[36, 'minecraft:golden_helmet']],
]]);
$check(
    'a malformed slot entry is reported instead of throwing',
    str_contains($messages($ops), 'malformed slot entry'),
    $messages($ops)
);

$ops = $profile->tick([[
    'k' => 'player.inventory',
    'player' => $uuid,
    'slots' => [[99, 'minecraft:stone', 1, 0]],
]]);
$check(
    'a slot outside the mirrored range is reported',
    str_contains($messages($ops), 'slot 99 is outside the mirrored range'),
    $messages($ops)
);

$ops = $profile->tick([[
    'k' => 'player.inventory',
    'player' => '99999999-0000-0000-0000-000000000000',
    'slots' => [[0, 'minecraft:stone', 1, 0]],
]]);
$check(
    'an inventory for an unknown player is reported once',
    substr_count($messages($ops), 'update for an unknown player') === 1,
    $messages($ops)
);
$ops = $profile->tick([[
    'k' => 'player.inventory',
    'player' => '99999999-0000-0000-0000-000000000000',
    'slots' => [[0, 'minecraft:stone', 1, 0]],
]]);
$check(
    'and not repeated on every following tick',
    !str_contains($messages($ops), 'inventory:'),
    $messages($ops)
);

// ---------------------------------------------------------------- quit
$profile->tick([['k' => 'event', 'name' => 'player.quit', 'player' => $uuid, 'data' => ['reason' => 'test']]]);
$ops = $profile->tick([[
    'k' => 'player.inventory',
    'player' => $uuid,
    'slots' => [[0, 'minecraft:stone', 1, 0]],
]]);
$check(
    'an inventory frame after quit neither reaches a ghost nor bounces back',
    $slotsOf($ops) === [],
    (string) json_encode($ops)
);
$idleOps = $idle();
$check(
    'the host keeps ticking after a late inventory frame',
    $slotsOf($idleOps) === [],
    (string) json_encode($slotsOf($idleOps))
);

$profile->shutdown();

echo $failures === 0 ? "inventory PASS\n" : "inventory FAILED ({$failures})\n";
exit($failures === 0 ? 0 : 1);
