#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Packet whitelist acceptance (host half): real PocketMine packets become bridge ops with semantic fields.
 *
 * The plugin (examples/pm-packet-probe) sends one of every whitelisted packet through the live
 * session of a bridged player - the same call path a real plugin uses. This script asserts the
 * ops the host produced and writes them to a fixture that the JVM half
 * (mukkit-nukkit/src/test/java/cn/mukkit/nukkit/PacketMapperCheck.java) maps and encodes with
 * Nukkit's own encoder. Neither half is much use alone: this one proves the fields are right,
 * that one proves Nukkit accepts them.
 *
 * Usage: php pm-host/tests/pm-packet-test.php [--fixture=path/to/ops.json]
 */

require __DIR__ . '/../src/bootstrap.php';

use Mukkit\Host\PmProfile;

$root = dirname(__DIR__, 2);
$pmRoot = getenv('MUKKIT_PM_ROOT') ?: dirname($root) . '/PocketMine-MP';

if (!is_file($pmRoot . '/src/mukkit/polyfill.php') || !is_file($pmRoot . '/vendor/autoload.php')) {
    fwrite(STDERR, "FAIL  fork not usable at {$pmRoot} (need src/mukkit/polyfill.php + vendor/autoload.php)\n");
    exit(1);
}

$fixturePath = $root . '/run/m4/ops.json';
foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('/^--fixture=(.+)$/', $arg, $m) === 1) {
        $fixturePath = $m[1];
    }
}

$base = $root . '/run/pm-m4';
$plugins = $base . '/plugins';
$data = $base . '/data';
$phar = $plugins . '/MukkitPacketProbe.phar';

exec('rm -rf ' . escapeshellarg($base));
mkdir($plugins, 0777, true);
mkdir($data, 0777, true);

passthru(
    'bash ' . escapeshellarg($root . '/scripts/build-plugin-phar.sh')
    . ' ' . escapeshellarg($root . '/examples/pm-packet-probe')
    . ' ' . escapeshellarg($phar),
    $buildCode
);
if (($buildCode ?? 1) !== 0) {
    fwrite(STDERR, "FAIL  cannot build MukkitPacketProbe.phar\n");
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

/** @return list<array<string, mixed>> */
$packetsOf = static function (array $ops, string $class): array {
    return array_values(array_filter(
        $ops,
        static fn (array $op): bool => ($op['o'] ?? '') === 'packet' && ($op['cls'] ?? '') === $class
    ));
};

$logText = static function (array $ops): string {
    $lines = [];
    foreach ($ops as $op) {
        if (($op['o'] ?? '') === 'log') {
            $lines[] = (string) ($op['msg'] ?? '');
        }
    }
    return implode("\n", $lines);
};

$uuid = '11111111-2222-3333-4444-555555555555';
$profile = new PmProfile($pmRoot, $data, $plugins);
$result = $profile->load([['name' => 'MukkitPacketProbe', 'path' => $phar]]);

$names = array_map(static fn (array $p): string => (string) ($p['name'] ?? ''), $result['loaded']);
$check('packet probe phar loaded by PM\'s own PluginManager', in_array('MukkitPacketProbe', $names, true), implode(',', $names));

// A player has to exist first: the packets are addressed to a session, and the host can only
// resolve that session by a UUID it has already bridged.
$profile->tick([[
    'k' => 'event',
    'name' => 'player.join',
    'player' => $uuid,
    'data' => ['name' => 'Steve', 'x' => 12.5, 'y' => 64.0, 'z' => -3.25, 'yaw' => 0.0, 'pitch' => 0.0, 'world' => 'world'],
]]);

$ops = $profile->tick([[
    'k' => 'event',
    'name' => 'player.chat',
    'player' => $uuid,
    'data' => ['name' => 'Steve', 'message' => '/m4packets'],
]]);

$logs = $logText($ops);
$check('the probe command ran inside PM', str_contains($logs, 'm4packets sent'), trim($logs));

// ---------------------------------------------------------------- title family

$titles = $packetsOf($ops, 'SetTitlePacket');
$titleTypes = array_map(static fn (array $op): string => (string) ($op['f']['type'] ?? ''), $titles);
sort($titleTypes);
$check(
    'all four title variants became their own ops',
    $titleTypes === ['actionbar', 'subtitle', 'times', 'title'],
    (string) json_encode($titleTypes)
);

$byType = [];
foreach ($titles as $op) {
    $byType[(string) ($op['f']['type'] ?? '')] = $op['f'];
}
$check(
    'title text and animation times survive',
    ($byType['title']['text'] ?? '') === 'Mukkit Packet'
        && ($byType['times']['fadeInTime'] ?? -1) === 10
        && ($byType['times']['stayTime'] ?? -1) === 40
        && ($byType['times']['fadeOutTime'] ?? -1) === 10,
    (string) json_encode($byType)
);

// ---------------------------------------------------------------- toast + form

$toasts = $packetsOf($ops, 'ToastRequestPacket');
$check(
    'toast carries both strings',
    count($toasts) === 1
        && ($toasts[0]['f']['title'] ?? '') === 'Mukkit'
        && ($toasts[0]['f']['body'] ?? '') === 'toast body',
    (string) json_encode($toasts)
);

$forms = $packetsOf($ops, 'ModalFormRequestPacket');
$formJson = (string) ($forms[0]['f']['formData'] ?? '');
$decodedForm = json_decode($formJson, true);
$check(
    'form keeps its id and its JSON payload',
    count($forms) === 1
        && ($forms[0]['f']['formId'] ?? 0) === 7
        && is_array($decodedForm)
        && ($decodedForm['title'] ?? '') === 'Pick one'
        && count($decodedForm['buttons'] ?? []) === 2,
    (string) json_encode($forms)
);

// The close packet has no fields at all; what can be checked is that it exists as an op rather
// than being dropped by the whitelist for having nothing to map.
$check(
    'close-form travels as a payloadless op',
    count($packetsOf($ops, 'ClientboundCloseFormPacket')) === 1,
    (string) json_encode($packetsOf($ops, 'ClientboundCloseFormPacket'))
);

// ---------------------------------------------------------------- sound

$sounds = $packetsOf($ops, 'PlaySoundPacket');
$check(
    'sound keeps its name, position and mix',
    count($sounds) === 1
        && ($sounds[0]['f']['soundName'] ?? '') === 'random.orb'
        && abs((float) ($sounds[0]['f']['x'] ?? 0) - 12.5) < 1e-6
        && abs((float) ($sounds[0]['f']['y'] ?? 0) - 64.0) < 1e-6
        && abs((float) ($sounds[0]['f']['z'] ?? 0) + 3.25) < 1e-6
        && abs((float) ($sounds[0]['f']['volume'] ?? 0) - 0.7) < 1e-6
        && abs((float) ($sounds[0]['f']['pitch'] ?? 0) - 1.4) < 1e-6,
    (string) json_encode($sounds)
);

// ---------------------------------------------------------------- scoreboard

$objectives = $packetsOf($ops, 'SetDisplayObjectivePacket');
$check(
    'sidebar objective keeps slot, name, title and sort order',
    count($objectives) === 1
        && ($objectives[0]['f']['displaySlot'] ?? '') === 'sidebar'
        && ($objectives[0]['f']['objectiveName'] ?? '') === 'mukkit_obj'
        && ($objectives[0]['f']['displayName'] ?? '') === 'Mukkit Board'
        && ($objectives[0]['f']['criteriaName'] ?? '') === 'dummy'
        && ($objectives[0]['f']['sortOrder'] ?? -1) === 1,
    (string) json_encode($objectives)
);

$scores = $packetsOf($ops, 'SetScorePacket');
$entries = $scores[0]['f']['entries'] ?? [];
$playerEntry = null;
$fakeEntry = null;
foreach ($entries as $entry) {
    if (($entry['type'] ?? '') === 'player') {
        $playerEntry = $entry;
    } elseif (($entry['type'] ?? '') === 'fake_player') {
        $fakeEntry = $entry;
    }
}
$check(
    'score packet keeps both entries and their scorer kinds',
    count($scores) === 1
        && ($scores[0]['f']['type'] ?? '') === 'change'
        && count($entries) === 2
        && ($playerEntry['scoreboardId'] ?? 0) === 1
        && ($playerEntry['score'] ?? 0) === 42
        && is_int($playerEntry['actorUniqueId'] ?? null)
        && ($fakeEntry['scoreboardId'] ?? 0) === 2
        && ($fakeEntry['customName'] ?? '') === 'Steve',
    (string) json_encode($scores)
);

$removals = $packetsOf($ops, 'RemoveObjectivePacket');
$check(
    'objective removal names the objective',
    count($removals) === 1 && ($removals[0]['f']['objectiveName'] ?? '') === 'mukkit_obj',
    (string) json_encode($removals)
);

$texts = $packetsOf($ops, 'TextPacket');
$check(
    'text still maps alongside the new classes',
    count($texts) >= 1 && ($texts[0]['f']['message'] ?? '') === 'm4 raw text',
    (string) json_encode($texts)
);

// ---------------------------------------------------------------- the boundary

$check(
    'a packet outside the whitelist is reported once, by class name',
    str_contains($logs, 'packet.unsupported') && str_contains($logs, 'SetTimePacket'),
    trim($logs)
);
$check(
    'no op is ever emitted for it',
    $packetsOf($ops, 'SetTimePacket') === [],
    (string) json_encode($packetsOf($ops, 'SetTimePacket'))
);

$profile->shutdown();

// ---------------------------------------------------------------- delivery ownership

// The JVM suppresses its own chat/join broadcast only when the host promises to deliver. This
// is the host half of that contract; run-m1.sh asserts the other half for the `plugin` profile.
$check(
    'the pm profile claims delivery',
    \Mukkit\Host\Delivery::ownerFor('pm') === \Mukkit\Host\Delivery::HOST,
    \Mukkit\Host\Delivery::ownerFor('pm')
);
$check(
    'observing profiles leave delivery to Nukkit',
    \Mukkit\Host\Delivery::ownerFor('plugin') === \Mukkit\Host\Delivery::JVM
        && \Mukkit\Host\Delivery::ownerFor('stub') === \Mukkit\Host\Delivery::JVM,
    \Mukkit\Host\Delivery::ownerFor('plugin') . '/' . \Mukkit\Host\Delivery::ownerFor('stub')
);

// ---------------------------------------------------------------- fixture

$whitelist = [
    'TextPacket',
    'SetTitlePacket',
    'ModalFormRequestPacket',
    'ClientboundCloseFormPacket',
    'ToastRequestPacket',
    'PlaySoundPacket',
    'SetDisplayObjectivePacket',
    'SetScorePacket',
    'RemoveObjectivePacket',
];

$fixtureOps = array_values(array_filter(
    $ops,
    static fn (array $op): bool => ($op['o'] ?? '') === 'packet' && in_array($op['cls'] ?? '', $whitelist, true)
));

$covered = array_values(array_unique(array_map(static fn (array $op): string => (string) $op['cls'], $fixtureOps)));
$missing = array_values(array_diff($whitelist, $covered));
$check(
    'the fixture covers every whitelisted class',
    $missing === [],
    'missing: ' . implode(',', $missing)
);

@mkdir(dirname($fixturePath), 0777, true);
$written = file_put_contents(
    $fixturePath,
    json_encode(['source' => 'pm-host/tests/pm-packet-test.php', 'ops' => $fixtureOps], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
);
$check('fixture written for the JVM half', $written !== false, $fixturePath);

if ($failures > 0) {
    fwrite(STDERR, "\npacket mapping: {$failures} failure(s)\n");
    exit(1);
}

echo "\npacket mapping (host half) PASS  " . count($fixtureOps) . " ops\n";
exit(0);
