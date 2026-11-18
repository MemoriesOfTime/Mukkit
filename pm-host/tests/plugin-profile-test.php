#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Drives PluginProfile directly, without a socket or a JVM.
 *
 * The phar is built on demand so the test always exercises the current example plugin.
 */

require __DIR__ . '/../src/bootstrap.php';

use Mukkit\Host\PluginProfile;

$root = dirname(__DIR__, 2);
$phar = $root . '/examples/stub-chat/build/StubChat.phar';

if (!is_file($phar)) {
    $builder = $root . '/scripts/build-example-plugin.sh';
    passthru('bash ' . escapeshellarg($builder), $buildCode);
    if (($buildCode ?? 1) !== 0 || !is_file($phar)) {
        fwrite(STDERR, "cannot build example phar, expected {$phar}\n");
        exit(1);
    }
}

$failures = 0;

$check = static function (string $label, bool $ok) use (&$failures): void {
    if ($ok) {
        echo "ok    {$label}\n";
        return;
    }
    echo "FAIL  {$label}\n";
    $failures++;
};

$logs = static fn (array $ops): array => array_values(array_filter(
    $ops,
    static fn (array $op): bool => ($op['o'] ?? '') === 'log'
));

$packets = static fn (array $ops): array => array_values(array_filter(
    $ops,
    static fn (array $op): bool => ($op['o'] ?? '') === 'packet'
));

$hasLog = static function (array $ops, string $level, string $needle) use ($logs): bool {
    foreach ($logs($ops) as $op) {
        if (($op['level'] ?? '') === $level && str_contains((string) ($op['msg'] ?? ''), $needle)) {
            return true;
        }
    }
    return false;
};

$hasChatTo = static function (array $ops, string $to, string $needle) use ($packets): bool {
    foreach ($packets($ops) as $op) {
        if (($op['to'] ?? '') !== $to || ($op['cls'] ?? '') !== 'TextPacket') {
            continue;
        }
        if (($op['f']['type'] ?? '') !== 'chat') {
            continue;
        }
        if (str_contains((string) ($op['f']['message'] ?? ''), $needle)) {
            return true;
        }
    }
    return false;
};

$uuid = '11111111-1111-1111-1111-111111111111';

// 1. load
$profile = new PluginProfile();
$load = $profile->load([['name' => 'StubChat', 'path' => $phar]]);
$check('load: no rejected entries', $load['rejected'] === []);
$check('load: StubChat present', ($load['loaded'][0]['name'] ?? '') === 'StubChat');
$check('load: status is loaded', ($load['loaded'][0]['status'] ?? '') === 'loaded');
$check('load: version from plugin.yml', ($load['loaded'][0]['version'] ?? '') === '1.0.0');
$check('load: api from plugin.yml', ($load['loaded'][0]['api'] ?? '') === '5.0.0');
$check('load: does not enable (no ops surface)', $load['rejected'] === []);

// 2. enable, twice
$ops = $profile->tick([['k' => 'plugin', 'plugin' => 'StubChat', 'name' => 'enable']]);
$check('enable: onEnable log emitted', $hasLog($ops, 'info', 'StubChat enabled'));

$ops = $profile->tick([['k' => 'plugin', 'plugin' => 'StubChat', 'name' => 'enable']]);
$check('enable: second enable is idempotent', !$hasLog($ops, 'info', 'StubChat enabled'));

// 3. player.join
$ops = $profile->tick([[
    'k' => 'event',
    'name' => 'player.join',
    'player' => $uuid,
    'data' => ['name' => 'Steve', 'xuid' => '2535400000000001'],
]]);
$check('join: chat packet to joined player', $hasChatTo($ops, $uuid, 'Steve'));
$check('join: welcome text kept', $hasChatTo($ops, $uuid, '欢迎'));

// 4. player.chat
$ops = $profile->tick([[
    'k' => 'event',
    'name' => 'player.chat',
    'player' => $uuid,
    'data' => ['name' => 'Steve', 'message' => 'hello'],
]]);
$check('chat: broadcast packet', $hasChatTo($ops, '*', 'hello'));

// 5. a broken inbound item must not stop the items after it
$ops = $profile->tick([
    ['k' => 'event', 'name' => 'player.chat', 'data' => []],
    [
        'k' => 'event',
        'name' => 'player.quit',
        'player' => $uuid,
        'data' => ['name' => 'Steve', 'reason' => 'client disconnect'],
    ],
]);
$check('bad item: error log emitted', $hasLog($ops, 'error', 'inbound event failed'));
$check('bad item: next item still processed', $hasChatTo($ops, '*', '离开了'));

// 6. disable, twice
$ops = $profile->tick([['k' => 'plugin', 'plugin' => 'StubChat', 'name' => 'disable']]);
$check('disable: onDisable log emitted', $hasLog($ops, 'info', 'StubChat disabled'));

$ops = $profile->tick([['k' => 'plugin', 'plugin' => 'StubChat', 'name' => 'disable']]);
$check('disable: second disable is idempotent', !$hasLog($ops, 'info', 'StubChat disabled'));

// 7. disabled plugins receive nothing
$ops = $profile->tick([[
    'k' => 'event',
    'name' => 'player.chat',
    'player' => $uuid,
    'data' => ['name' => 'Steve', 'message' => 'after disable'],
]]);
$check('disabled: no ops produced', $ops === []);

// 8. rejection paths stay local to the offending plugin
$rejector = new PluginProfile();
$result = $rejector->load([['name' => 'Ghost', 'path' => $root . '/examples/does-not-exist.phar']]);
$check(
    'reject: phar.missing',
    str_starts_with((string) ($result['rejected'][0]['reason'] ?? ''), 'phar.missing:')
);

$tmp = tempnam(sys_get_temp_dir(), 'mukkit-bad-');
file_put_contents($tmp, "this is not a phar\n");
$result = $rejector->load([['name' => 'Broken', 'path' => $tmp]]);
$check(
    'reject: plugin.yml.invalid',
    str_starts_with((string) ($result['rejected'][0]['reason'] ?? ''), 'plugin.yml.invalid:')
);
@unlink($tmp);

// 9. one bad plugin cannot stop a good one in the same batch
$mixed = new PluginProfile();
$result = $mixed->load([
    ['name' => 'Ghost', 'path' => $root . '/examples/does-not-exist.phar'],
    ['name' => 'StubChat', 'path' => $phar],
]);
$check('mixed batch: good plugin still loads', ($result['loaded'][0]['name'] ?? '') === 'StubChat');
$check('mixed batch: bad plugin rejected', count($result['rejected']) === 1);

// 10. shutdown is safe after disable
$threw = false;
try {
    $profile->shutdown();
} catch (Throwable) {
    $threw = true;
}
$check('shutdown: does not throw', !$threw);

echo "\n";
if ($failures !== 0) {
    echo "{$failures} assertion(s) failed\n";
    exit(1);
}
echo "all assertions passed\n";
exit(0);
