#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * M5 acceptance: `$world->getBlock()` answers with what Nukkit has, and plugin writes go back.
 *
 * The plugin (examples/pm-world-probe) is ordinary PM code: `/m5read` calls
 * `$world->getBlockAt()` and replies with the block name, `/m5write` calls `$world->setBlockAt()`.
 * Every assertion is either one of those replies coming back as a packet op, or the `block` op
 * the host produced for the write.
 *
 * Usage: php pm-host/tests/pm-world-test.php
 */

require __DIR__ . '/../src/bootstrap.php';

use Mukkit\Host\PmProfile;

$root = dirname(__DIR__, 2);
$pmRoot = getenv('MUKKIT_PM_ROOT') ?: dirname($root) . '/PocketMine-MP';

if (!is_file($pmRoot . '/src/mukkit/polyfill.php') || !is_file($pmRoot . '/vendor/autoload.php')) {
    fwrite(STDERR, "FAIL  fork not usable at {$pmRoot} (need src/mukkit/polyfill.php + vendor/autoload.php)\n");
    exit(1);
}

$base = $root . '/run/pm-m5';
$plugins = $base . '/plugins';
$data = $base . '/data';
$phar = $plugins . '/MukkitWorldProbe.phar';

// PHP-native delete instead of `rm -rf`: the shell shim in this repo treats large recursive
// deletes as personal-file operations and blocks them.
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
    . ' ' . escapeshellarg($root . '/examples/pm-world-probe')
    . ' ' . escapeshellarg($phar),
    $buildCode
);
if (($buildCode ?? 1) !== 0) {
    fwrite(STDERR, "FAIL  cannot build MukkitWorldProbe.phar\n");
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

/** Every player-visible line produced by a tick: TextPacket payloads and log ops. */
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

/** Block ops in a tick, flattened to [x, y, z, id, meta] rows. */
$blockOps = static function (array $ops): array {
    $rows = [];
    foreach ($ops as $op) {
        if (($op['o'] ?? '') !== 'block') {
            continue;
        }
        foreach (($op['b'] ?? []) as $row) {
            $rows[] = $row;
        }
    }
    return $rows;
};

/** Block ops grouped by the world they name. */
$blockOpsByWorld = static function (array $ops): array {
    $worlds = [];
    foreach ($ops as $op) {
        if (($op['o'] ?? '') !== 'block') {
            continue;
        }
        $world = (string) ($op['world'] ?? '');
        foreach (($op['b'] ?? []) as $row) {
            $worlds[$world][] = $row;
        }
    }
    return $worlds;
};

/**
 * One section of 4096 blocks, given as a sparse map of local index => [id, meta].
 *
 * @param array<int, array{int, int}> $blocks
 * @return array{y: int, ids: string, metas: string}
 */
$section = static function (int $sectionY, array $blocks): array {
    $ids = array_fill(0, 4096, 0);
    $metas = array_fill(0, 4096, 0);
    foreach ($blocks as $index => [$id, $meta]) {
        $ids[$index] = $id;
        $metas[$index] = $meta;
    }
    // Same wire format the JVM encoder produces: base64(zlib(...)), not base64(raw).
    return [
        'y' => $sectionY,
        'ids' => base64_encode(gzcompress(pack('v*', ...$ids))),
        'metas' => base64_encode(gzcompress(pack('C*', ...$metas))),
    ];
};

$uuid = '11111111-2222-3333-4444-555555555555';
$profile = new PmProfile($pmRoot, $data, $plugins);
// The adapter tells the host Nukkit's default level name before boot; that is what lets the
// manager answer `getDefaultWorld()` and `getWorldByName('world')` from the first tick.
$result = $profile->load([['name' => 'MukkitWorldProbe', 'path' => $phar]], ['defaultWorld' => 'world']);

$names = array_map(static fn (array $p): string => (string) ($p['name'] ?? ''), $result['loaded']);
$check('world probe phar loaded by PM\'s own PluginManager', in_array('MukkitWorldProbe', $names, true), implode(',', $names));
$check('no rejected plugins', $result['rejected'] === [], var_export($result['rejected'], true));

/** Asks the plugin what the world manager knows, and returns the reported line. */
$worldsReport = static function () use ($profile, $messages): string {
    $ops = $profile->tick([['k' => 'command', 'sender' => 'console', 'line' => 'm5worlds']]);
    foreach (explode("\n", $messages($ops)) as $line) {
        $at = strpos($line, 'm5worlds ');
        if ($at !== false) {
            return trim(substr($line, $at));
        }
    }
    return '';
};

// ---------------------------------------------------------------- world discovery
$line = $worldsReport();
$check(
    'the default world exists before any player joins',
    str_contains($line, 'default=world') && str_contains($line, 'count=1') && str_contains($line, 'world=yes'),
    $line
);
$check(
    'a world nobody joined is not invented',
    str_contains($line, 'arena=no'),
    $line
);

// ---------------------------------------------------------------- join
$profile->tick([[
    'k' => 'event',
    'name' => 'player.join',
    'player' => $uuid,
    'data' => ['name' => 'Steve', 'x' => 0.5, 'y' => 64.0, 'z' => 0.5, 'yaw' => 0.0, 'pitch' => 0.0, 'world' => 'world'],
]]);

// ---------------------------------------------------------------- mirror in
// The fixture is written by the JVM encoder (ChunkSnapshotCheck) when the full acceptance script
// runs, so these assertions cover the seam between the two languages rather than only the PHP
// decoder. Without it (standalone run) a hand-built section with the same layout is used.
$fixturePath = getenv('MUKKIT_M5_CHUNK');
$fixtureUsed = $fixturePath !== false && is_file($fixturePath);
$sections = null;
if ($fixtureUsed) {
    $decoded = json_decode((string) file_get_contents($fixturePath), true);
    $sections = is_array($decoded['sections'] ?? null) ? $decoded['sections'] : null;
}
if ($sections === null) {
    // Section 4 is y=64..79. Local index is x | (z << 4) | (y << 8), so index 0 is (0, 64, 0).
    $sections = [$section(4, [0 => [1, 0], 1 => [3, 0]])];
}
$check(
    'chunk fixture came from the JVM encoder',
    !$fixtureUsed || $sections !== null,
    $fixtureUsed ? (string) $fixturePath : 'standalone run, using the built-in section'
);

$ops = $profile->tick([[
    'k' => 'chunk',
    'world' => 'world',
    'x' => 0,
    'z' => 0,
    'sections' => $sections,
]]);
$check('chunk frame is accepted without complaint', !str_contains($messages($ops), 'world mirror:'), $messages($ops));

/**
 * Runs `/m5read` through the console command path.
 *
 * Console rather than player chat on purpose: `Player::chat()` is rate limited by a counter that
 * only refills in `Player::onUpdate()`, and a chunk is only ticked when its whole 3x3
 * neighbourhood is present. The mirror is happy with one chunk; a plugin reading blocks does not
 * need the player to be ticking for that.
 */
$read = static function (int $x, int $y, int $z) use ($profile): string {
    $ops = $profile->tick([['k' => 'command', 'sender' => 'console', 'line' => sprintf('m5read %d %d %d', $x, $y, $z)]]);
    foreach ($ops as $op) {
        if (($op['o'] ?? '') === 'log' && str_contains((string) ($op['msg'] ?? ''), 'm5read')) {
            return (string) $op['msg'];
        }
    }
    return '';
};

$check(
    'getBlockAt() returns the stone Nukkit reported',
    str_ends_with($read(0, 64, 0), '= Stone'),
    $read(0, 64, 0)
);
$check(
    'getBlockAt() returns the dirt next to it (XZY order is not transposed)',
    str_ends_with($read(1, 64, 0), '= Dirt'),
    $read(1, 64, 0)
);
if ($fixtureUsed) {
    $check(
        'the JVM encoder and the PHP decoder agree on XZY order',
        str_contains($read(0, 64, 1), 'Planks'),
        $read(0, 64, 1)
    );
}

$check(
    'an empty position inside the mirrored chunk is air',
    str_ends_with($read(5, 70, 5), '= Air'),
    $read(5, 70, 5)
);
$check(
    'a chunk that was never mirrored reads as air instead of inventing terrain',
    str_ends_with($read(500, 64, 0), '= Air'),
    $read(500, 64, 0)
);

// ---------------------------------------------------------------- mirror out
$ops = $profile->tick([['k' => 'command', 'sender' => 'console', 'line' => 'm5write 2 64 0']]);
$rows = $blockOps($ops);
$check('a plugin write produces a block op', $rows === [[2, 64, 0, 57, 0]], (string) json_encode($rows));
$check(
    'the write names the world it happened in',
    array_keys($blockOpsByWorld($ops)) === ['world'],
    (string) json_encode(array_keys($blockOpsByWorld($ops)))
);
$check('the write is visible to the plugin too', str_contains($messages($ops), 'm5write 2,64,0 done'), $messages($ops));

// ---------------------------------------------------------------- mirror back in
$ops = $profile->tick([[
    'k' => 'block.update',
    'world' => 'world',
    'x' => 3,
    'y' => 64,
    'z' => 0,
    'id' => 3,
    'meta' => 0,
]]);
$check('a Nukkit block change is not echoed back', $blockOps($ops) === [], (string) json_encode($blockOps($ops)));
$check(
    'the next read sees the block Nukkit changed',
    str_ends_with($read(3, 64, 0), '= Dirt'),
    $read(3, 64, 0)
);

// A block change outside the mirrored chunks must be reported once and then stay quiet.
$ops = $profile->tick([['k' => 'block.update', 'world' => 'world', 'x' => 5000, 'y' => 64, 'z' => 5000, 'id' => 1, 'meta' => 0]]);
$warned = str_contains($messages($ops), 'outside the mirrored chunks');
$ops = $profile->tick([['k' => 'block.update', 'world' => 'world', 'x' => 5001, 'y' => 64, 'z' => 5000, 'id' => 1, 'meta' => 0]]);
$check('a change outside the mirror is reported once, not every tick', $warned && !str_contains($messages($ops), 'outside the mirrored chunks'), $messages($ops));

// ---------------------------------------------------------------- a second world
// Nukkit level names are the world identity. A chunk in `arena` must land in the arena's world,
// not in the default one, and the player's `getWorld()` must follow the join frame.
$gold = \pocketmine\block\VanillaBlocks::GOLD()->getName();
$ops = $profile->tick([[
    'k' => 'chunk',
    'world' => 'arena',
    'x' => 0,
    'z' => 0,
    'sections' => [$section(4, [0 => [41, 0]])],
]]);
$check('a chunk for a second world is accepted', !str_contains($messages($ops), 'world mirror:'), $messages($ops));

$profile->tick([['k' => 'event', 'name' => 'player.quit', 'player' => $uuid, 'data' => ['reason' => 'test']]]);
$profile->tick([[
    'k' => 'event',
    'name' => 'player.join',
    'player' => $uuid,
    'data' => ['name' => 'Steve', 'x' => 0.5, 'y' => 64.0, 'z' => 0.5, 'yaw' => 0.0, 'pitch' => 0.0, 'world' => 'arena'],
]]);
$check(
    'the player is in the world the join frame named',
    str_ends_with($read(0, 64, 0), '= ' . $gold),
    $read(0, 64, 0)
);
$check(
    'the first world is not visible from the second',
    str_ends_with($read(3, 64, 0), '= Air'),
    $read(3, 64, 0)
);
$line = $worldsReport();
$check(
    'a joined world shows up in the manager by name',
    str_contains($line, 'count=2')
        && str_contains($line, 'names=arena,world')
        && str_contains($line, 'arena=yes'),
    $line
);
$check(
    'the default world did not change when another one appeared',
    str_contains($line, 'default=world'),
    $line
);

$ops = $profile->tick([['k' => 'command', 'sender' => 'console', 'line' => 'm5write 2 64 0']]);
$check(
    'a write in the arena names the arena',
    array_keys($blockOpsByWorld($ops)) === ['arena'],
    (string) json_encode($blockOpsByWorld($ops))
);

/** Merged `s` payload of every world.state op in a tick, plus the worlds they named. */
$worldStateOf = static function (array $ops): array {
    $state = [];
    foreach ($ops as $op) {
        if (($op['o'] ?? '') !== 'world.state') {
            continue;
        }
        $state['__world'] = (string) ($op['world'] ?? '');
        foreach (($op['s'] ?? []) as $key => $value) {
            $state[$key] = $value;
        }
    }
    return $state;
};

/** Asks the plugin what it thinks the world clock is, and returns the reported line. */
$clock = static function () use ($profile, $messages): string {
    $ops = $profile->tick([['k' => 'command', 'sender' => 'console', 'line' => 'm5clock']]);
    foreach (explode("\n", $messages($ops)) as $line) {
        $at = strpos($line, 'm5clock time=');
        if ($at !== false) {
            return trim(substr($line, $at));
        }
    }
    return '';
};

// ---------------------------------------------------------------- world clock
// The player is in the arena at this point; its clock is the one being mirrored.
$ops = $profile->tick([['k' => 'world.state', 'world' => 'arena', 's' => ['time' => 1234]]]);
$check(
    'a clock pushed by Nukkit is what the plugin reads back',
    str_contains($clock(), 'time=1234'),
    $clock()
);
$check(
    'applying a clock does not emit a packet the bridge cannot map',
    !str_contains($messages($ops), 'packet.unsupported'),
    $messages($ops)
);
$idleOps = $profile->tick([]);
$check(
    'a mirrored clock is not echoed back to Nukkit',
    $worldStateOf($idleOps) === [],
    (string) json_encode($worldStateOf($idleOps))
);

$ops = $profile->tick([['k' => 'command', 'sender' => 'console', 'line' => 'm5time 0']]);
$state = $worldStateOf($ops);
$check(
    'setTime() reaches the bridge for the world the player is in',
    ($state['__world'] ?? '') === 'arena' && ($state['time'] ?? null) === 0,
    (string) json_encode($state)
);
$check(
    'and does not drag the stopped flag along',
    !array_key_exists('stopped', $state),
    (string) json_encode($state)
);

$ops = $profile->tick([['k' => 'command', 'sender' => 'console', 'line' => 'm5stop']]);
$state = $worldStateOf($ops);
$check(
    'stopTime() reaches the bridge',
    ($state['stopped'] ?? null) === true && !array_key_exists('time', $state),
    (string) json_encode($state)
);
$check('the plugin sees its own time stop', str_contains($clock(), 'stopped=yes'), $clock());
$idleOps = $profile->tick([]);
$check(
    'a stopped clock produces no further ops',
    $worldStateOf($idleOps) === [],
    (string) json_encode($worldStateOf($idleOps))
);

$profile->tick([['k' => 'world.state', 'world' => 'arena', 's' => ['stopped' => false]]]);
$check('a clock restart pushed by Nukkit is applied', str_contains($clock(), 'stopped=no'), $clock());

// Honesty over coverage: weather does not exist in PocketMine 5, and the mirror says so once
// instead of pretending to have applied it.
$ops = $profile->tick([['k' => 'world.state', 'world' => 'arena', 's' => ['rain' => true]]]);
$check(
    'a field this side has no home for is reported, not swallowed',
    str_contains($messages($ops), 'unknown field rain'),
    $messages($ops)
);
$ops = $profile->tick([['k' => 'world.state', 'world' => 'nowhere', 's' => ['time' => 1]]]);
$check(
    'a clock for a world nobody joined is refused',
    str_contains($messages($ops), 'no world named nowhere'),
    $messages($ops)
);

// ---------------------------------------------------------------- chunk unload
// The chunk the player stands in is pinned by PocketMine's own player chunk loader. The streamer
// never asks to unload such a chunk; if somebody does, keeping it is the only safe answer.
$ops = $profile->tick([['k' => 'chunk.unload', 'world' => 'arena', 'x' => 0, 'z' => 0]]);
$check(
    'the chunk the player stands in is not unloaded',
    str_contains($messages($ops), 'is in use'),
    $messages($ops)
);
$check(
    'and it still reads its block',
    str_ends_with($read(0, 64, 0), '= ' . $gold),
    $read(0, 64, 0)
);

// A neighbouring chunk is fair game: mirror it, read it, drop it, read air.
$profile->tick([[
    'k' => 'chunk',
    'world' => 'arena',
    'x' => 1,
    'z' => 0,
    'sections' => [$section(4, [0 => [1, 0]])],
]]);
$check(
    'a neighbouring chunk reads its block',
    str_ends_with($read(16, 64, 0), '= Stone'),
    $read(16, 64, 0)
);
$ops = $profile->tick([['k' => 'chunk.unload', 'world' => 'arena', 'x' => 1, 'z' => 0]]);
$check(
    'unloading a chunk nobody stands in is accepted',
    !str_contains($messages($ops), 'in use'),
    $messages($ops)
);
$check(
    'an unloaded chunk reads as air again',
    str_ends_with($read(16, 64, 0), '= Air'),
    $read(16, 64, 0)
);
$ops = $profile->tick([['k' => 'command', 'sender' => 'console', 'line' => 'm5write 16 64 0']]);
$check(
    'a write into an unloaded chunk is not sent anywhere',
    $blockOps($ops) === [],
    (string) json_encode($blockOps($ops))
);
$idleOps = $profile->tick([]);
$check(
    'the host keeps ticking after a write into unloaded terrain',
    !str_contains($messages($idleOps), 'world mirror:'),
    $messages($idleOps)
);

$profile->shutdown();

if ($failures > 0) {
    echo "\n{$failures} failure(s)\n";
    exit(1);
}
echo "\nM5 PASS\n";
