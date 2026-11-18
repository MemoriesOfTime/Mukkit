#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Cancellable-event acceptance, second event: a PocketMine plugin can veto a Nukkit item drop.
 *
 * The plugin (examples/pm-drop-probe) cancels diamonds. The frames below are what the adapter
 * sends when a player throws a stack out of the window, and the assertions are the verdict that
 * comes back, the stack PocketMine saw, and the refusals that keep a broken frame from hanging.
 *
 * Usage: php pm-host/tests/pm-drop-event-test.php
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

$base = $root . '/run/pm-drop-event';
$plugins = $base . '/plugins';
$data = $base . '/data';
$phar = $plugins . '/MukkitDropProbe.phar';

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
    . ' ' . escapeshellarg($root . '/examples/pm-drop-probe')
    . ' ' . escapeshellarg($phar),
    $buildCode
);
if (($buildCode ?? 1) !== 0) {
    fwrite(STDERR, "FAIL  cannot build MukkitDropProbe.phar\n");
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

$uuid = '11111111-2222-3333-4444-666666666666';
$profile = new PmProfile($pmRoot, $data, $plugins);
$result = $profile->load([['name' => 'MukkitDropProbe', 'path' => $phar]]);

$names = array_map(static fn (array $p): string => (string) ($p['name'] ?? ''), $result['loaded']);
$check('drop probe phar loaded by PM\'s own PluginManager', in_array('MukkitDropProbe', $names, true), implode(',', $names));

$check(
    'a listener makes the host ask for drops',
    in_array('player.drop', $profile->eventDemand(), true),
    (string) json_encode($profile->eventDemand())
);

$profile->tick([[
    'k' => 'event',
    'name' => 'player.join',
    'player' => $uuid,
    'data' => ['name' => 'Steve', 'x' => 0.5, 'y' => 64.0, 'z' => 0.5, 'yaw' => 0.0, 'pitch' => 0.0, 'world' => 'world'],
]]);

/** Builds the frame the adapter sends when a player drops a stack. */
$dropFrame = static function (int $id, array $item, string $player = '') use ($uuid): array {
    return [
        'k' => 'event',
        'name' => 'player.drop',
        'player' => $player === '' ? $uuid : $player,
        'id' => $id,
        'data' => ['item' => $item],
    ];
};

// ---------------------------------------------------------------- denied
$ops = $profile->tick([$dropFrame(1, ['minecraft:diamond', 2, 0])]);
$text = $messages($ops);
$check('dropping diamonds is denied', $verdict($ops, 1) === false, (string) json_encode($ops));
$check('the plugin saw the real stack from the tuple', str_contains($text, 'drop item=Diamond x2'), $text);
$check('the plugin reported the cancellation', str_contains($text, 'drop denied'), $text);

// ---------------------------------------------------------------- allowed
$ops = $profile->tick([$dropFrame(2, ['minecraft:dirt', 3, 0])]);
$text = $messages($ops);
$check('dropping dirt is allowed', $verdict($ops, 2) === true, (string) json_encode($ops));
$check('and the plugin looked at it without cancelling', str_contains($text, 'drop item=Dirt x3') && !str_contains($text, 'drop denied'), $text);

// Damage is durability now, so a worn sword arrives worn and is not denied for being a diamond item.
$ops = $profile->tick([$dropFrame(3, ['minecraft:diamond_sword', 1, 7])]);
$text = $messages($ops);
$check('a worn sword keeps its damage', $verdict($ops, 3) === true && str_contains($text, 'dmg=7'), $text);

// The tuple's optional fifth element is the NBT blob, and it has to survive the trip: a plugin
// deciding on a drop is usually deciding on the item's name, not on its id.
$named = StringToItemParser::getInstance()->parse('diamond_sword');
if ($named === null) {
    $check('the test can build a named item', false, 'StringToItemParser refused diamond_sword');
} else {
    $named->setCustomName('Alice');
    $nbt = base64_encode((new NetworkNbtSerializer())->write(new TreeRoot($named->getNamedTag())));
    $ops = $profile->tick([$dropFrame(8, ['minecraft:diamond_sword', 1, 0, $nbt])]);
    $check(
        'a renamed stack arrives renamed',
        $verdict($ops, 8) === true && str_contains($messages($ops), 'name=Alice'),
        $messages($ops)
    );
}

// ---------------------------------------------------------------- refusals
$ops = $profile->tick([$dropFrame(4, ['minecraft:not_a_real_item', 1, 0])]);
$text = $messages($ops);
$check('an item this build does not have is denied, not left hanging', $verdict($ops, 4) === false, (string) json_encode($ops));
$check(
    'and says why',
    str_contains($text, 'frame could not be turned into a PocketMine event'),
    $text
);

$ops = $profile->tick([$dropFrame(5, ['minecraft:air', 0, 0])]);
$check('dropping air is denied rather than dispatched as an empty event', $verdict($ops, 5) === false, (string) json_encode($ops));

$ops = $profile->tick([[
    'k' => 'event',
    'name' => 'player.drop',
    'player' => $uuid,
    'id' => 6,
    'data' => [],
]]);
$check('a frame with no item is denied', $verdict($ops, 6) === false, (string) json_encode($ops));

$ops = $profile->tick([$dropFrame(7, ['minecraft:diamond', 1, 0], '99999999-0000-0000-0000-000000000000')]);
$text = $messages($ops);
$check('a drop for an unknown player is denied', $verdict($ops, 7) === false, (string) json_encode($ops));
$check(
    'and that refusal is reported once',
    str_contains($text, 'event player.drop: no bridged player'),
    $text
);

$ops = $profile->tick([[
    'k' => 'event',
    'name' => 'player.drop',
    'player' => $uuid,
    'data' => ['item' => ['minecraft:diamond', 1, 0]],
]]);
$check(
    'a frame with no request id produces no verdict',
    $verdict($ops, 0) === null && !str_contains((string) json_encode($ops), 'event.result'),
    (string) json_encode($ops)
);

// ---------------------------------------------------------------- demand follows the plugins
$ops = $profile->tick([['k' => 'plugin', 'name' => 'disable', 'plugin' => 'MukkitDropProbe']]);
$check(
    'disabling the listener withdraws the demand in the same tick',
    in_array(['o' => 'capabilities', 'events' => []], $ops, true),
    (string) json_encode($ops)
);
$check('and the host reports no demand afterwards', $profile->eventDemand() === [], (string) json_encode($profile->eventDemand()));

$profile->shutdown();

echo $failures === 0 ? "drop events PASS\n" : "drop events FAILED ({$failures})\n";
exit($failures === 0 ? 0 : 1);
