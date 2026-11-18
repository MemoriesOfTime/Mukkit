#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Kick acceptance: what a plugin's `Player::kick()` does to the player on the other side.
 *
 * A kick is the first thing in the bridge that ends a player rather than changing it, and it has
 * two ways to go silently wrong. If the disconnect is never mapped, the client stays connected to
 * a player the plugin believes is gone. If the host runs its own quit path *and* the quit that
 * comes back, `PlayerQuitEvent` fires twice and plugins tear down twice - which for something
 * like EconomyAPI means saving a balance for a player who already left.
 *
 * The plugin (examples/pm-state-probe) uses only ordinary PocketMine API: `Player::kick()`.
 *
 * Usage: php pm-host/tests/pm-kick-test.php
 */

require __DIR__ . '/../src/bootstrap.php';

use Mukkit\Host\PmProfile;

$root = dirname(__DIR__, 2);
$pmRoot = getenv('MUKKIT_PM_ROOT') ?: dirname($root) . '/PocketMine-MP';

if (!is_file($pmRoot . '/src/mukkit/polyfill.php') || !is_file($pmRoot . '/vendor/autoload.php')) {
    fwrite(STDERR, "FAIL  fork not usable at {$pmRoot} (need src/mukkit/polyfill.php + vendor/autoload.php)\n");
    exit(1);
}

$base = $root . '/run/pm-kick';
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

/** The packet ops of a tick. */
$packets = static function (array $ops): array {
    return array_values(array_filter($ops, static fn (array $op): bool => ($op['o'] ?? '') === 'packet'));
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
$join = [[
    'k' => 'event',
    'name' => 'player.join',
    'player' => $uuid,
    'data' => ['name' => 'Steve', 'x' => 0.5, 'y' => 64.0, 'z' => 0.5, 'yaw' => 0.0, 'pitch' => 0.0, 'world' => 'world', 'op' => false],
]];

$profile = new PmProfile($pmRoot, $data, $plugins);
$result = $profile->load([['name' => 'MukkitStateProbe', 'path' => $phar]]);

$names = array_map(static fn (array $p): string => (string) ($p['name'] ?? ''), $result['loaded']);
$check('state probe phar loaded by PM\'s own PluginManager', in_array('MukkitStateProbe', $names, true), implode(',', $names));

/** Runs one console command and returns the ops of that tick. */
$command = static function (string $line) use ($profile): array {
    return $profile->tick([['k' => 'command', 'sender' => 'console', 'line' => $line]]);
};

$profile->tick($join);

// ---------------------------------------------------------------- kick
$ops = $command('st-kick cheating');
$packetsThisTick = $packets($ops);
$kick = $packetsThisTick[0] ?? [];
$check(
    'a kick produces exactly one packet op',
    count($packetsThisTick) === 1,
    (string) json_encode($packetsThisTick)
);
$check(
    'and it is the disconnection screen',
    ($kick['cls'] ?? '') === 'DisconnectPacket',
    (string) json_encode($kick)
);
$check(
    'addressed to the player who was kicked',
    ($kick['to'] ?? '') === $uuid,
    (string) json_encode($kick)
);
$check(
    'carrying the reason the plugin gave',
    ($kick['f']['message'] ?? '') === 'cheating',
    (string) json_encode($kick['f'] ?? [])
);
// `Player::kick()` runs the quit path itself, before the packet is even queued. If the host ran
// it a second time on the way back, this would be 2.
$check(
    'PlayerQuitEvent fires once, from the kick',
    substr_count($messages($ops), 'st-quit name=Steve') === 1,
    $messages($ops)
);
$check(
    'the quit event carries the same reason',
    str_contains($messages($ops), 'st-quit name=Steve reason=cheating'),
    $messages($ops)
);

// ---------------------------------------------------------------- after the kick
$idle = $profile->tick([]);
$check(
    'a kicked player is not diffed on later ticks',
    $stateOf($idle) === [] && !str_contains($messages($idle), 'st-quit'),
    $messages($idle) . (string) json_encode($stateOf($idle))
);

$late = $profile->tick([['k' => 'player.state', 'player' => $uuid, 's' => ['health' => 5.0]]]);
$check(
    'a state frame for a kicked player is reported, not applied',
    str_contains($messages($late), 'unknown player'),
    $messages($late)
);

// The other direction of the same race: Nukkit saw the client go away and says so. The host
// already ran the quit path, so this must not become a second event.
$echo = $profile->tick([[
    'k' => 'event',
    'name' => 'player.quit',
    'player' => $uuid,
    'data' => ['name' => 'Steve', 'reason' => 'client disconnected'],
]]);
$check(
    'a quit Nukkit sends afterwards does not fire a second quit event',
    !str_contains($messages($echo), 'st-quit'),
    $messages($echo)
);

// ---------------------------------------------------------------- edge cases
$profile->tick($join);
$ops = $command('st-kick');
$kick = $packets($ops)[0] ?? [];
$check(
    'a kick without a reason still has something to show on the screen',
    ($kick['f']['message'] ?? '') !== '',
    (string) json_encode($kick['f'] ?? [])
);

$ops = $command('st-kick');
$check(
    'kicking nobody is a no-op, not a broadcast',
    $packets($ops) === [] && str_contains($messages($ops), 'no player online'),
    $messages($ops)
);

$profile->shutdown();

echo $failures === 0 ? "kick PASS\n" : "kick FAILED ({$failures})\n";
exit($failures === 0 ? 0 : 1);
