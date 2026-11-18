#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * M3 acceptance: a real phar plugin sees real `Player` objects for bridge events.
 *
 * The plugin (examples/pm-probe) is ordinary PM code: it extends PluginBase, listens to
 * PlayerJoinEvent/PlayerChatEvent/PlayerQuitEvent, and calls the four APIs the roadmap names -
 * getName(), sendMessage(), getPosition(), plus an inventory round trip. Every assertion below
 * is one of its log lines coming back through the bridge, or a `packet` op it caused.
 *
 * Usage: php pm-host/tests/pm-player-test.php
 */

require __DIR__ . '/../src/bootstrap.php';

use Mukkit\Host\PmProfile;

$root = dirname(__DIR__, 2);
$pmRoot = getenv('MUKKIT_PM_ROOT') ?: dirname($root) . '/PocketMine-MP';

if (!is_file($pmRoot . '/src/mukkit/polyfill.php') || !is_file($pmRoot . '/vendor/autoload.php')) {
    fwrite(STDERR, "FAIL  fork not usable at {$pmRoot} (need src/mukkit/polyfill.php + vendor/autoload.php)\n");
    exit(1);
}

$base = $root . '/run/pm-m3';
$plugins = $base . '/plugins';
$data = $base . '/data';
$phar = $plugins . '/MukkitProbe.phar';

exec('rm -rf ' . escapeshellarg($base));
mkdir($plugins, 0777, true);
mkdir($data, 0777, true);

passthru(
    'bash ' . escapeshellarg($root . '/scripts/build-plugin-phar.sh')
    . ' ' . escapeshellarg($root . '/examples/pm-probe')
    . ' ' . escapeshellarg($phar),
    $buildCode
);
if (($buildCode ?? 1) !== 0) {
    fwrite(STDERR, "FAIL  cannot build MukkitProbe.phar\n");
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

/** All log lines in a tick's ops, joined. */
$text = static function (array $ops): string {
    $lines = [];
    foreach ($ops as $op) {
        if (($op['o'] ?? '') === 'log') {
            $lines[] = (string) ($op['msg'] ?? '');
        }
    }
    return implode("\n", $lines);
};

/** Packet ops addressed to one player. */
$packetsTo = static function (array $ops, string $target): array {
    return array_values(array_filter(
        $ops,
        static fn (array $op): bool => ($op['o'] ?? '') === 'packet' && ($op['to'] ?? '') === $target
    ));
};

$uuid = '11111111-2222-3333-4444-555555555555';
$profile = new PmProfile($pmRoot, $data, $plugins);
$result = $profile->load([['name' => 'MukkitProbe', 'path' => $phar]]);

$names = array_map(static fn (array $p): string => (string) ($p['name'] ?? ''), $result['loaded']);
$check('probe phar loaded by PM\'s own PluginManager', in_array('MukkitProbe', $names, true), implode(',', $names));
$check('no rejected plugins', $result['rejected'] === [], var_export($result['rejected'], true));

// ---------------------------------------------------------------- join
$ops = $profile->tick([[
    'k' => 'event',
    'name' => 'player.join',
    'player' => $uuid,
    'data' => ['name' => 'Steve', 'x' => 12.5, 'y' => 64.0, 'z' => -3.25, 'yaw' => 90.0, 'pitch' => 0.0, 'world' => 'world'],
]]);
$output = $text($ops);

$check(
    'PlayerJoinEvent reached the plugin with a real Player',
    str_contains($output, 'probe join name=Steve'),
    trim($output)
);
$check(
    'getPosition() matches what Nukkit reported',
    str_contains($output, 'pos=12.50,64.00,-3.25'),
    trim($output)
);
$check(
    'getWorld() is the bridge world',
    (bool) preg_match('/world=[A-Za-z0-9_]+/', $output),
    trim($output)
);
$check(
    'inventory round trip: a written item reads back',
    (bool) preg_match('/item=\d+ x3\b/', $output),
    trim($output)
);

$toPlayer = $packetsTo($ops, $uuid);
$messages = array_map(static fn (array $op): string => (string) ($op['f']['message'] ?? ''), $toPlayer);

$check(
    'sendMessage() produced a TextPacket op for this player',
    in_array('welcome Steve', $messages, true),
    (string) json_encode($messages)
);
$check(
    'the join message was broadcast back to the player',
    count(array_filter($messages, static fn (string $m): bool => str_contains($m, 'Steve joined the game'))) === 1,
    (string) json_encode($messages)
);
$check(
    'every op for this player is an unmapped-raw TextPacket',
    $toPlayer !== [] && array_reduce(
        $toPlayer,
        static fn (bool $ok, array $op): bool => $ok && ($op['cls'] ?? '') === 'TextPacket' && ($op['f']['type'] ?? '') === 'raw',
        true
    ),
    (string) json_encode($toPlayer)
);

// ---------------------------------------------------------------- chat
$ops = $profile->tick([[
    'k' => 'event',
    'name' => 'player.chat',
    'player' => $uuid,
    'data' => ['name' => 'Steve', 'message' => 'hello world'],
]]);
$output = $text($ops);
$check('PlayerChatEvent reached the plugin', str_contains($output, 'probe chat from=Steve message=hello world'), trim($output));

$toPlayer = $packetsTo($ops, $uuid);
$broadcast = '';
foreach ($toPlayer as $op) {
    $broadcast .= (string) ($op['f']['message'] ?? '');
}
$check(
    'the formatted chat came back as a packet to the player',
    str_contains($broadcast, 'Steve') && str_contains($broadcast, 'hello world'),
    $broadcast === '' ? 'no packet op' : $broadcast
);

// A leading slash must become a command dispatched *as this player*: Player::chat() routes it
// through the CommandMap with the bridged player as sender, and the reply comes back as a
// packet to that player - not to console.
$ops = $profile->tick([[
    'k' => 'event',
    'name' => 'player.chat',
    'player' => $uuid,
    'data' => ['name' => 'Steve', 'message' => '/probe'],
]]);
$reply = '';
foreach ($packetsTo($ops, $uuid) as $op) {
    $reply .= (string) ($op['f']['message'] ?? '');
}
$check(
    'a player-issued command runs with the player as sender',
    str_contains($reply, 'probe pong name=Steve') && str_contains($reply, 'pos=12.50,64.00,-3.25'),
    $reply === '' ? 'no packet op' : $reply
);

// ---------------------------------------------------------------- quit
$ops = $profile->tick([[
    'k' => 'event',
    'name' => 'player.quit',
    'player' => $uuid,
    'data' => ['name' => 'Steve', 'reason' => 'client quit'],
]]);
$check('PlayerQuitEvent reached the plugin', str_contains($text($ops), 'probe quit name=Steve'), trim($text($ops)));

$ops = $profile->tick([[
    'k' => 'event',
    'name' => 'player.chat',
    'player' => $uuid,
    'data' => ['name' => 'Steve', 'message' => 'still here?'],
]]);
$check('chat after quit is refused, not delivered to a phantom player', str_contains($text($ops), 'unproxied player'), trim($text($ops)));

// ---------------------------------------------------------------- unknown events
$ops = $profile->tick([['k' => 'event', 'name' => 'world.weather', 'player' => $uuid, 'data' => []]]);
$check('unknown event names are reported, not swallowed', str_contains($text($ops), 'event.unsupported'), trim($text($ops)));

$ops = $profile->tick([['k' => 'event', 'name' => 'world.weather', 'player' => $uuid, 'data' => []]]);
$check('the unsupported warning is not repeated every tick', !str_contains($text($ops), 'event.unsupported'), trim($text($ops)));

// ---------------------------------------------------------------- lifecycle
$ops = $profile->tick([['k' => 'event', 'name' => 'player.join', 'player' => $uuid, 'data' => ['name' => 'Steve']]]);
$check('rejoin after quit works', str_contains($text($ops), 'probe join name=Steve'), trim($text($ops)));

$profile->shutdown();

if ($failures > 0) {
    fwrite(STDERR, "\nM3 player proxy: {$failures} failure(s)\n");
    exit(1);
}

echo "\nM3 player proxy PASS\n";
exit(0);
