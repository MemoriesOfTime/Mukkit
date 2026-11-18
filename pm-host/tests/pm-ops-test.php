#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Operator acceptance: a player who is an operator on Nukkit is an operator in PocketMine, and
 * the other way round.
 *
 * Why this is its own script rather than a few more lines in `pm-player-state-test.php`: getting
 * op wrong does not show up as a wrong number, it shows up as a plugin refusing a command it
 * should have allowed. So the assertion is the thing plugins actually check -
 * `$player->hasPermission()` on a node declared with `default: op` in `plugin.yml` - and not an
 * internal flag.
 *
 * The plugin (examples/pm-state-probe) only uses ordinary PocketMine API: `Server::addOp()` /
 * `Server::removeOp()` to change it, `hasPermission()` to read it.
 *
 * Usage: php pm-host/tests/pm-ops-test.php
 */

require __DIR__ . '/../src/bootstrap.php';

use Mukkit\Host\PmProfile;

$root = dirname(__DIR__, 2);
$pmRoot = getenv('MUKKIT_PM_ROOT') ?: dirname($root) . '/PocketMine-MP';

if (!is_file($pmRoot . '/src/mukkit/polyfill.php') || !is_file($pmRoot . '/vendor/autoload.php')) {
    fwrite(STDERR, "FAIL  fork not usable at {$pmRoot} (need src/mukkit/polyfill.php + vendor/autoload.php)\n");
    exit(1);
}

$base = $root . '/run/pm-ops';
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
$join = static fn (bool $operator): array => [[
    'k' => 'event',
    'name' => 'player.join',
    'player' => $uuid,
    'data' => [
        'name' => 'Steve',
        'x' => 0.5,
        'y' => 64.0,
        'z' => 0.5,
        'yaw' => 0.0,
        'pitch' => 0.0,
        'world' => 'world',
        'op' => $operator,
    ],
]];

$profile = new PmProfile($pmRoot, $data, $plugins);
$result = $profile->load([['name' => 'MukkitStateProbe', 'path' => $phar]]);

$names = array_map(static fn (array $p): string => (string) ($p['name'] ?? ''), $result['loaded']);
$check('state probe phar loaded by PM\'s own PluginManager', in_array('MukkitStateProbe', $names, true), implode(',', $names));

/** Runs one console command and returns the ops of that tick. */
$command = static function (string $line) use ($profile): array {
    return $profile->tick([['k' => 'command', 'sender' => 'console', 'line' => $line]]);
};

/** Asks the plugin what it thinks the player's permissions are, and returns the reported line. */
$report = static function () use ($command, $messages): string {
    $ops = $command('st-report');
    foreach (explode("\n", $messages($ops)) as $line) {
        $at = strpos($line, 'st-report x=');
        if ($at !== false) {
            return trim(substr($line, $at));
        }
    }
    return '';
};

// ---------------------------------------------------------------- join
$joinOps = $profile->tick($join(true));
$check(
    'a join carries the operator bit instead of reporting it a tick later',
    $stateOf($joinOps) === [],
    (string) json_encode($stateOf($joinOps))
);
// The probe logs from inside `PlayerJoinEvent`, so this is the ordering assertion: PocketMine
// reads its operator list once, in the player constructor, before plugins get the event.
$check(
    'PlayerJoinEvent already sees the operator bit',
    str_contains($messages($joinOps), 'st-join name=Steve op=true admin=true'),
    $messages($joinOps)
);

$line = $report();
$check(
    'an operator who joins is an operator in PocketMine',
    str_contains($line, 'op=true'),
    $line
);
// This is the assertion that matters: `mukkit.stateprobe.admin` is declared `default: op` in
// plugin.yml, exactly like `/setmoney` in an economy plugin. If it is granted, the economy
// command will run; if it is not, the plugin refuses it in a way nobody can debug.
$check(
    'and the node a plugin declared with default: op is granted',
    str_contains($line, 'admin=true'),
    $line
);

$idleOps = $profile->tick([]);
$check(
    'an operator who does nothing produces no state op',
    $stateOf($idleOps) === [],
    (string) json_encode($stateOf($idleOps))
);

// ---------------------------------------------------------------- Nukkit -> PM
$ops = $profile->tick([['k' => 'player.state', 'player' => $uuid, 's' => ['op' => false]]]);
$line = $report();
$check(
    'a de-op pushed by Nukkit takes the permission away',
    str_contains($line, 'op=false') && str_contains($line, 'admin=false'),
    $line
);
$check(
    'the de-op is not echoed back to Nukkit',
    $stateOf($profile->tick([])) === [],
    (string) json_encode($stateOf($profile->tick([])))
);

// A frame that carries op and something else must apply both, in one tick.
$profile->tick([['k' => 'player.state', 'player' => $uuid, 's' => ['op' => true, 'gamemode' => 'creative']]]);
$line = $report();
$check(
    'a frame carrying op and game mode applies both',
    str_contains($line, 'op=true') && str_contains($line, 'gm=creative'),
    $line
);

// ---------------------------------------------------------------- PM -> Nukkit
$ops = $command('st-op off');
$state = $stateOf($ops);
$check(
    'Server::removeOp() reaches Nukkit as an operator change',
    array_key_exists('op', $state) && $state['op'] === false,
    (string) json_encode($state)
);
$check(
    'the operator change travels alone',
    array_keys($state) === ['op'],
    (string) json_encode($state)
);
$check(
    'and the plugin immediately sees itself without the permission',
    str_contains($report(), 'admin=false'),
    $report()
);

$ops = $command('st-op on');
$state = $stateOf($ops);
$check(
    'Server::addOp() reaches Nukkit as an operator change',
    array_key_exists('op', $state) && $state['op'] === true,
    (string) json_encode($state)
);
// PocketMine's operator change is a permission recalculation, not a player-visible event: if it
// also emitted packets, Nukkit would send them twice (once from each side's view of the change).
$packets = array_values(array_filter($ops, static fn (array $op): bool => ($op['o'] ?? '') === 'packet'));
$check(
    'changing op does not send a packet from PocketMine',
    $packets === [],
    (string) json_encode($packets)
);
$check(
    'the operator bit is reported once, not every tick',
    $stateOf($profile->tick([])) === [],
    (string) json_encode($stateOf($profile->tick([])))
);

// ---------------------------------------------------------------- rejoin
// PocketMine persists `ops.txt`, so an entry outlives the session. Nukkit is the authority: a
// player who rejoins without the bit must not inherit the stored entry.
$command('st-op on');
$profile->tick([['k' => 'event', 'name' => 'player.quit', 'player' => $uuid, 'data' => ['reason' => 'test']]]);
$profile->tick($join(false));
$line = $report();
$check(
    'a stored operator entry is cleared when Nukkit says the player is not an operator',
    str_contains($line, 'op=false') && str_contains($line, 'admin=false'),
    $line
);

$profile->tick([['k' => 'event', 'name' => 'player.quit', 'player' => $uuid, 'data' => ['reason' => 'test']]]);
$profile->tick($join(true));
$line = $report();
$check(
    'and granted when Nukkit says the player is one',
    str_contains($line, 'op=true') && str_contains($line, 'admin=true'),
    $line
);

// ---------------------------------------------------------------- unknown players
$ops = $profile->tick([[
    'k' => 'player.state',
    'player' => '99999999-8888-7777-6666-555555555555',
    's' => ['op' => true],
]]);
$check(
    'an operator change for an unknown player is reported, not applied',
    str_contains($messages($ops), 'unknown player') && $stateOf($ops) === [],
    $messages($ops)
);

$profile->shutdown();

echo $failures === 0 ? "ops PASS\n" : "ops FAILED ({$failures})\n";
exit($failures === 0 ? 0 : 1);
