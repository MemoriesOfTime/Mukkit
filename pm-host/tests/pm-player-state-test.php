#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Player-state acceptance: what a plugin changes about a player reaches Nukkit, and what Nukkit
 * changes reaches the plugin.
 *
 * The plugin (examples/pm-state-probe) is ordinary PM code: `/st-move`, `/st-health` and
 * `/st-gm` call `teleport()`, `setHealth()` and `setGamemode()`. `/st-report` reads the same state
 * back, which is how the inbound direction is asserted: a `player.state` frame from Nukkit must be
 * visible to the plugin on the next tick.
 *
 * Nothing here uses a bridge-specific API, and that is the point: if this passes, any plugin that
 * changes player state works.
 *
 * Usage: php pm-host/tests/pm-player-state-test.php
 */

require __DIR__ . '/../src/bootstrap.php';

use Mukkit\Host\PmProfile;

$root = dirname(__DIR__, 2);
$pmRoot = getenv('MUKKIT_PM_ROOT') ?: dirname($root) . '/PocketMine-MP';

if (!is_file($pmRoot . '/src/mukkit/polyfill.php') || !is_file($pmRoot . '/vendor/autoload.php')) {
    fwrite(STDERR, "FAIL  fork not usable at {$pmRoot} (need src/mukkit/polyfill.php + vendor/autoload.php)\n");
    exit(1);
}

$base = $root . '/run/pm-player-state';
$plugins = $base . '/plugins';
$data = $base . '/data';
$phar = $plugins . '/MukkitStateProbe.phar';

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
    . ' ' . escapeshellarg($root . '/examples/pm-state-probe')
    . ' ' . escapeshellarg($phar),
    $buildCode
);
if (($buildCode ?? 1) !== 0) {
    fwrite(STDERR, "FAIL  cannot build MukkitStateProbe.phar\n");
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

/** Everything a player would see this tick: TextPacket payloads and log lines. */
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

/** The merged `s` payload of every player.state op in a tick. */
$stateOf = static function (array $ops): array {
    $state = [];
    foreach ($ops as $op) {
        if (($op['o'] ?? '') !== 'player.state') {
            continue;
        }
        foreach (($op['s'] ?? []) as $key => $value) {
            $state[$key] = $value;
        }
    }
    return $state;
};

$uuid = '11111111-2222-3333-4444-555555555555';
$profile = new PmProfile($pmRoot, $data, $plugins);
$result = $profile->load([['name' => 'MukkitStateProbe', 'path' => $phar]]);

$names = array_map(static fn (array $p): string => (string) ($p['name'] ?? ''), $result['loaded']);
$check('state probe phar loaded by PM\'s own PluginManager', in_array('MukkitStateProbe', $names, true), implode(',', $names));
$check('no rejected plugins', $result['rejected'] === [], var_export($result['rejected'], true));

/** Runs one console command and returns the ops of that tick. */
$command = static function (string $line) use ($profile): array {
    return $profile->tick([['k' => 'command', 'sender' => 'console', 'line' => $line]]);
};

/** Asks the plugin what it thinks the state is, and returns the reported line. */
$report = static function () use ($command, $messages): string {
    $ops = $command('st-report');
    foreach (explode("\n", $messages($ops)) as $line) {
        //Console output arrives with PocketMine's own "Command output |" prefix, so match on the
        //payload rather than the start of the line.
        $at = strpos($line, 'st-report x=');
        if ($at !== false) {
            return trim(substr($line, $at));
        }
    }
    return '';
};

// ---------------------------------------------------------------- join
$joinOps = $profile->tick([[
    'k' => 'event',
    'name' => 'player.join',
    'player' => $uuid,
    'data' => ['name' => 'Steve', 'x' => 0.5, 'y' => 64.0, 'z' => 0.5, 'yaw' => 0.0, 'pitch' => 0.0, 'world' => 'world', 'heldSlot' => 4],
]]);

$check(
    'join seeds the shadow instead of reporting a change',
    $stateOf($joinOps) === [],
    (string) json_encode($stateOf($joinOps))
);
// The probe logs from inside `PlayerJoinEvent`: a plugin reading `getItemInHand()` there must
// already see the slot the client is holding, not slot 0.
$check(
    'the join frame lands the held slot before PlayerJoinEvent',
    str_contains($messages($joinOps), 'st-join name=Steve op=false admin=false held=4'),
    $messages($joinOps)
);

$idleOps = $profile->tick([]);
$check(
    'an idle tick produces no player.state op',
    $stateOf($idleOps) === [],
    (string) json_encode($stateOf($idleOps))
);

// ---------------------------------------------------------------- outbound: PM -> Nukkit

$ops = $command('st-move 12.5 70 -3.25 90 15');
$state = $stateOf($ops);
$check(
    'teleport() reaches the bridge as a position update',
    ($state['x'] ?? null) === 12.5 && ($state['y'] ?? null) === 70.0 && ($state['z'] ?? null) === -3.25,
    (string) json_encode($state)
);
$check(
    'the teleport carries yaw and pitch with it',
    ($state['yaw'] ?? null) === 90.0 && ($state['pitch'] ?? null) === 15.0,
    (string) json_encode($state)
);
$check(
    'a teleport does not drag health or gamemode along',
    !array_key_exists('health', $state) && !array_key_exists('gamemode', $state),
    (string) json_encode($state)
);

$idleOps = $profile->tick([]);
$check(
    'the position is reported once, not every tick',
    $stateOf($idleOps) === [],
    (string) json_encode($stateOf($idleOps))
);

$ops = $command('st-health 7');
$state = $stateOf($ops);
$check(
    'setHealth() reaches the bridge',
    ($state['health'] ?? null) === 7.0 && !array_key_exists('x', $state),
    (string) json_encode($state)
);

$ops = $command('st-gm creative');
$state = $stateOf($ops);
$check(
    'setGamemode() reaches the bridge',
    ($state['gamemode'] ?? null) === 'creative' && !array_key_exists('x', $state),
    (string) json_encode($state)
);

$ops = $command('st-xp 7 0.42');
$state = $stateOf($ops);
$check(
    'experience reaches the bridge as level and progress',
    ($state['xpLevel'] ?? null) === 7 && abs(($state['xpProgress'] ?? 0.0) - 0.42) < 0.0001,
    (string) json_encode($state)
);

$ops = $command('st-food 18 4.5');
$state = $stateOf($ops);
$check(
    'hunger reaches the bridge as food and saturation',
    abs(($state['food'] ?? 0.0) - 18.0) < 0.0001 && abs(($state['saturation'] ?? 0.0) - 4.5) < 0.0001,
    (string) json_encode($state)
);

$ops = $command('st-hold 5');
$state = $stateOf($ops);
$check(
    'setHeldItemIndex() reaches the bridge as one small field',
    ($state['heldSlot'] ?? null) === 5 && !array_key_exists('x', $state),
    (string) json_encode($state)
);

$idleOps = $profile->tick([]);
$check(
    'the held slot is reported once, not every tick',
    $stateOf($idleOps) === [],
    (string) json_encode($stateOf($idleOps))
);

$ops = $command('st-effect 1 600 1');
$state = $stateOf($ops);
$check(
    'a potion effect reaches the bridge as one effect tuple',
    ($state['effects'] ?? null) === [[1, 600, 1, true, false]],
    (string) json_encode($state)
);

$idleOps = $profile->tick([]);
$check(
    'experience, hunger and effects are reported once, not every tick',
    $stateOf($idleOps) === [],
    (string) json_encode($stateOf($idleOps))
);

$ops = $command('st-cleareffects');
$state = $stateOf($ops);
$check(
    'clearing effects sends an empty list, not a missing field',
    array_key_exists('effects', $state) && $state['effects'] === [],
    (string) json_encode($state)
);

// ---------------------------------------------------------------- inbound: Nukkit -> PM

$profile->tick([[
    'k' => 'player.state',
    'player' => $uuid,
    's' => ['x' => 1.0, 'y' => 66.0, 'z' => 2.0, 'yaw' => 45.0, 'pitch' => 5.0],
]]);
$check(
    'a position pushed by Nukkit is what the plugin reads back',
    str_contains($report(), 'x=1.00 y=66.00 z=2.00 yaw=45.00 pitch=5.00'),
    $report()
);

$idleOps = $profile->tick([]);
$check(
    'mirrored movement is not echoed back to Nukkit',
    $stateOf($idleOps) === [],
    (string) json_encode($stateOf($idleOps))
);

$profile->tick([['k' => 'player.state', 'player' => $uuid, 's' => ['health' => 3.5]]]);
$check(
    'a health pushed by Nukkit is what the plugin reads back',
    str_contains($report(), 'health=3.50'),
    $report()
);
$afterHealth = $profile->tick([]);
$check(
    'mirrored health is not echoed back to Nukkit',
    $stateOf($afterHealth) === [],
    (string) json_encode($stateOf($afterHealth))
);

$profile->tick([['k' => 'player.state', 'player' => $uuid, 's' => ['gamemode' => 'survival']]]);
$check(
    'a gamemode pushed by Nukkit is what the plugin reads back',
    str_contains($report(), 'gm=survival'),
    $report()
);
$afterGamemode = $profile->tick([]);
$check(
    'mirrored gamemode is not echoed back to Nukkit',
    $stateOf($afterGamemode) === [],
    (string) json_encode($stateOf($afterGamemode))
);

$profile->tick([['k' => 'player.state', 'player' => $uuid, 's' => ['xpLevel' => 3, 'xpProgress' => 0.25]]]);
$check(
    'experience pushed by Nukkit is what the plugin reads back',
    str_contains($report(), 'xp=3:0.25'),
    $report()
);
$afterXp = $profile->tick([]);
$check(
    'mirrored experience is not echoed back to Nukkit',
    $stateOf($afterXp) === [],
    (string) json_encode($stateOf($afterXp))
);

$profile->tick([['k' => 'player.state', 'player' => $uuid, 's' => ['food' => 12, 'saturation' => 2.5]]]);
$check(
    'hunger pushed by Nukkit is what the plugin reads back',
    str_contains($report(), 'food=12.00:2.50'),
    $report()
);
$afterFood = $profile->tick([]);
$check(
    'mirrored hunger is not echoed back to Nukkit',
    $stateOf($afterFood) === [],
    (string) json_encode($stateOf($afterFood))
);

$profile->tick([['k' => 'player.state', 'player' => $uuid, 's' => ['heldSlot' => 2]]]);
$check(
    'a held slot pushed by Nukkit is what the plugin reads back',
    str_contains($report(), 'held=2'),
    $report()
);
$afterHeld = $profile->tick([]);
$check(
    'mirrored held slot is not echoed back to Nukkit',
    $stateOf($afterHeld) === [],
    (string) json_encode($stateOf($afterHeld))
);

$profile->tick([['k' => 'player.state', 'player' => $uuid, 's' => ['effects' => [[1, 400, 2, true, false]]]]]);
$check(
    'an effect pushed by Nukkit is what the plugin reads back',
    str_contains($report(), 'effects=1:2:400'),
    $report()
);
$afterEffect = $profile->tick([]);
$check(
    'mirrored effects are not echoed back to Nukkit',
    $stateOf($afterEffect) === [],
    (string) json_encode($stateOf($afterEffect))
);

$profile->tick([['k' => 'player.state', 'player' => $uuid, 's' => ['effects' => []]]]);
$check(
    'an empty effect list clears what was there',
    str_contains($report(), 'effects=none'),
    $report()
);
$afterClear = $profile->tick([]);
$check(
    'clearing effects is not echoed back to Nukkit',
    $stateOf($afterClear) === [],
    (string) json_encode($stateOf($afterClear))
);

// ---------------------------------------------------------------- refusals

$ops = $profile->tick([['k' => 'player.state', 'player' => $uuid, 's' => ['gamemode' => 'skyblock']]]);
$check(
    'an unknown gamemode is reported instead of guessed',
    str_contains($messages($ops), 'player state: unknown gamemode skyblock'),
    $messages($ops)
);
$check(
    'the refusal does not change the player',
    str_contains($report(), 'gm=survival'),
    $report()
);

$ops = $profile->tick([['k' => 'player.state', 'player' => $uuid, 's' => ['effects' => [[200, 400, 0, true, false]]]]]);
$check(
    'an effect id PocketMine does not know is reported instead of applied',
    str_contains($messages($ops), 'player state: effect: unknown effect id 200'),
    $messages($ops)
);
$check(
    'and it does not appear on the player',
    str_contains($report(), 'effects=none'),
    $report()
);

$ops = $profile->tick([['k' => 'player.state', 'player' => $uuid, 's' => ['heldSlot' => 9]]]);
$check(
    'a held slot outside 0-8 is reported instead of applied',
    str_contains($messages($ops), 'player state: held slot 9 is outside 0-8'),
    $messages($ops)
);
$check(
    'and the player keeps the slot it had',
    str_contains($report(), 'held=2'),
    $report()
);

$ops = $profile->tick([['k' => 'player.state', 'player' => '99999999-0000-0000-0000-000000000000', 's' => ['health' => 1.0]]]);
$check(
    'state for an unknown player is reported once',
    substr_count($messages($ops), 'player state: update for an unknown player') === 1,
    $messages($ops)
);
$ops = $profile->tick([['k' => 'player.state', 'player' => '99999999-0000-0000-0000-000000000000', 's' => ['health' => 1.0]]]);
$check(
    'and not repeated on every following tick',
    !str_contains($messages($ops), 'player state:'),
    $messages($ops)
);

// ---------------------------------------------------------------- death
// Nukkit reports a dead player as health 0. PocketMine's death path must not move the player:
// its respawn point is the bridge world's spawn, not Nukkit's, so a mirrored move here would
// yank the client somewhere it did not die.
$profile->tick([['k' => 'player.state', 'player' => $uuid, 's' => ['health' => 0.0]]]);
$check(
    'a death is applied to the PocketMine player',
    str_contains($report(), 'health=0.00'),
    $report()
);
$drift = [];
for ($i = 0; $i < 5; $i++) {
    foreach ($stateOf($profile->tick([])) as $key => $value) {
        $drift[$key] = $value;
    }
}
$check(
    'a death does not drag the client to PocketMine\'s spawn',
    $drift === [],
    (string) json_encode($drift)
);

// ---------------------------------------------------------------- join-time change
// A plugin that selects a hotbar slot while handling the join: the frame said 4, the plugin
// changed it to 6 inside PlayerJoinEvent, and Nukkit has to hear 6 on the next tick. Seeding
// the shadow from the post-event player would absorb the change instead.
$alex = '99999999-1111-2222-3333-444444444444';
$ops = $profile->tick([[
    'k' => 'event',
    'name' => 'player.join',
    'player' => $alex,
    'data' => ['name' => 'SlotJoin', 'x' => 0.5, 'y' => 64.0, 'z' => 0.5, 'yaw' => 0.0, 'pitch' => 0.0, 'world' => 'world', 'heldSlot' => 4],
]]);
$slotOp = null;
foreach ($ops as $op) {
    if (($op['o'] ?? '') === 'player.state' && ($op['to'] ?? '') === $alex) {
        $slotOp = $op['s'] ?? null;
    }
}
$check(
    'a slot selected inside PlayerJoinEvent still reaches Nukkit',
    is_array($slotOp) && ($slotOp['heldSlot'] ?? null) === 6,
    (string) json_encode($slotOp)
);
$check(
    'and the replay carries nothing else',
    is_array($slotOp) && array_keys($slotOp) === ['heldSlot'],
    (string) json_encode($slotOp)
);
$afterSlot = $profile->tick([]);
$check(
    'the join-time slot is reported once',
    $stateOf($afterSlot) === [],
    (string) json_encode($stateOf($afterSlot))
);
$profile->tick([['k' => 'event', 'name' => 'player.quit', 'player' => $alex, 'data' => ['reason' => 'test']]]);

// ---------------------------------------------------------------- quit
$profile->tick([['k' => 'event', 'name' => 'player.quit', 'player' => $uuid, 'data' => ['reason' => 'test']]]);
$ops = $profile->tick([['k' => 'player.state', 'player' => $uuid, 's' => ['health' => 1.0]]]);
$check(
    'state after quit neither reaches a ghost nor bounces back',
    $stateOf($ops) === [],
    (string) json_encode($ops)
);
$idleOps = $profile->tick([]);
$check(
    'the host keeps ticking after a late state frame',
    $stateOf($idleOps) === [],
    (string) json_encode($stateOf($idleOps))
);

$profile->shutdown();

echo $failures === 0 ? "player state PASS\n" : "player state FAILED ({$failures})\n";
exit($failures === 0 ? 0 : 1);
