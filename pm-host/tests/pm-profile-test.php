#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * pm profile end-to-end, without a socket or a JVM: boot the fork, load a real phar plugin
 * (EconomyAPI), dispatch a command through the bridge's inbound frame, read the ops back.
 *
 * This is the M2 acceptance: "EconomyAPI 真实加载". It fails loudly if the fork is missing
 * instead of skipping, because a silent skip is how a broken acceptance test survives.
 *
 * Usage: php pm-host/tests/pm-profile-test.php [--keep]
 */

require __DIR__ . '/../src/bootstrap.php';

use Mukkit\Host\PmProfile;

$root = dirname(__DIR__, 2);
$pmRoot = getenv('MUKKIT_PM_ROOT') ?: dirname($root) . '/PocketMine-MP';

if (!is_file($pmRoot . '/src/mukkit/polyfill.php') || !is_file($pmRoot . '/vendor/autoload.php')) {
    fwrite(STDERR, "FAIL  fork not usable at {$pmRoot} (need src/mukkit/polyfill.php + vendor/autoload.php)\n");
    exit(1);
}

$plugins = $root . '/run/pm-e2e/plugins';
$data = $root . '/run/pm-e2e/data';
$phar = $plugins . '/EconomyAPI.phar';

if (!is_file($phar)) {
    passthru('bash ' . escapeshellarg($root . '/scripts/build-economyapi.sh'), $buildCode);
    if (($buildCode ?? 1) !== 0) {
        fwrite(STDERR, "FAIL  cannot build EconomyAPI.phar\n");
        exit(1);
    }
    @mkdir($plugins, 0777, true);
    copy($root . '/run/m2/plugins/EconomyAPI.phar', $phar);
}

// Plugin data from a previous run would hide "onEnable created it" regressions.
if (!in_array('--keep', $argv, true)) {
    foreach ([$plugins . '/EconomyAPI', $data . '/plugin_data'] as $stale) {
        if (is_dir($stale)) {
            exec('rm -rf ' . escapeshellarg($stale));
        }
    }
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

$text = static function (array $ops): string {
    $lines = [];
    foreach ($ops as $op) {
        if (($op['o'] ?? '') === 'log') {
            $lines[] = (string) ($op['msg'] ?? '');
        }
    }
    return implode("\n", $lines);
};

$profile = new PmProfile($pmRoot, $data, $plugins);

$result = $profile->load([['name' => 'EconomyAPI', 'path' => $phar]]);
$names = array_map(static fn (array $p): string => (string) ($p['name'] ?? ''), $result['loaded']);
$check('phar loaded by PM\'s own PluginManager', in_array('EconomyAPI', $names, true), implode(',', $names));

$economy = null;
foreach ($result['loaded'] as $loaded) {
    if (($loaded['name'] ?? '') === 'EconomyAPI') {
        $economy = $loaded;
    }
}
$check('plugin reports enabled', ($economy['status'] ?? '') === 'enabled', (string) ($economy['status'] ?? 'missing'));
$check('plugin declares a PM API', ($economy['api'] ?? '') !== '', (string) ($economy['api'] ?? ''));

//The layout is the bridge's call, handed over as MUKKIT_PM_DATA_LAYOUT. The default is Nukkit's
//habit - plugins/<PluginName>/, beside the phar. `isolated` keeps it under the data dir instead.
//Both are asserted from the same test so the switch cannot rot on one side.
$layout = getenv('MUKKIT_PM_DATA_LAYOUT') ?: 'plugin-folder';
$pluginData = $layout === 'isolated' ? $data . '/plugin_data/EconomyAPI' : $plugins . '/EconomyAPI';
$otherData = $layout === 'isolated' ? $plugins . '/EconomyAPI' : $data . '/plugin_data/EconomyAPI';
$check("plugin data dir follows the '{$layout}' layout",
    is_file($pluginData . '/config.yml') && filesize($pluginData . '/config.yml') > 0, $pluginData);
$check('the other layout is not populated', !is_dir($otherData), $otherData);
$check('no rejected plugins', $result['rejected'] === [], var_export($result['rejected'], true));

// 1) tick with no input: PM scheduler runs, nothing should explode.
$ops = $profile->tick([]);
$check('idle tick produces ops without error', $text($ops) === '' || !str_contains(strtolower($text($ops)), 'error'));

// 2) a command frame, exactly as the JVM sends it.
// /setmoney refuses unknown names, so seed the account through the plugin's own API first - which
// also proves the plugin's public API is reachable from the host process.
$apiClass = 'onebone\\economyapi\\EconomyAPI';
$check('plugin API class is autoloadable', class_exists($apiClass));
$api = $apiClass::getInstance();
$api->createAccount('Steve', 100);
$check('account seeded through plugin API', $api->accountExists('Steve'));

$ops = $profile->tick([['k' => 'command', 'sender' => 'console', 'line' => 'setmoney Steve 4321']]);
$output = $text($ops);
$check('command reached the real CommandMap', str_contains($output, '4321'), trim($output));
$check('command output came back as log ops', !str_contains($output, 'not handled'), trim($output));

// 3) the balance the plugin actually stored, read back through its own API.
$ops = $profile->tick([['k' => 'command', 'sender' => 'console', 'line' => 'seemoney Steve']]);
$output = $text($ops);
$check('balance persisted across ticks', str_contains($output, '4321'), trim($output));

// 4) events without a PocketMine equivalent are refused, not faked. Player events themselves are
// covered by pm-player-test.php (M3), cancellable block events by pm-block-event-test.php.
// `entity.explode` is deliberately one nothing bridges: using a name that later gains a bridge
// would turn this assertion into a false failure, which is exactly what happened to block.break.
$ops = $profile->tick([['k' => 'event', 'name' => 'entity.explode', 'player' => '00000000-0000-0000-0000-000000000000', 'data' => []]]);
$check('event with no PM equivalent is reported as unsupported', str_contains($text($ops), 'event.unsupported'), trim($text($ops)));

$ops = $profile->tick([['k' => 'event', 'name' => 'entity.explode', 'player' => '00000000-0000-0000-0000-000000000000', 'data' => []]]);
$check('unsupported event warning is not repeated every tick', !str_contains($text($ops), 'event.unsupported'));

$profile->shutdown();

if ($failures > 0) {
    fwrite(STDERR, "\npm profile: {$failures} failure(s)\n");
    exit(1);
}

echo "\npm profile PASS\n";
exit(0);
