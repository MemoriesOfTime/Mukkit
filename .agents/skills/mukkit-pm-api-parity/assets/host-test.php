#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * 模板：PM 侧判据。把 xxx / Xxx 替换成能力名。
 *
 * 断言四个方向，缺一个就是单向绿：
 *   出站 → 不回声 → 入站读回 → 异常输入只报不应用。
 *
 * Usage: php pm-host/tests/pm-xxx-test.php
 */

require __DIR__ . '/../src/bootstrap.php';

use Mukkit\Host\PmProfile;

$root = dirname(__DIR__, 2);
$pmRoot = getenv('MUKKIT_PM_ROOT') ?: dirname($root) . '/PocketMine-MP';

if (!is_file($pmRoot . '/src/mukkit/polyfill.php') || !is_file($pmRoot . '/vendor/autoload.php')) {
    fwrite(STDERR, "FAIL  fork not usable at {$pmRoot} (need src/mukkit/polyfill.php + vendor/autoload.php)\n");
    exit(1);
}

$base = $root . '/run/pm-xxx';
$plugins = $base . '/plugins';
$data = $base . '/data';
$phar = $plugins . '/MukkitXxxProbe.phar';

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
    . ' ' . escapeshellarg($root . '/examples/pm-xxx-probe')
    . ' ' . escapeshellarg($phar),
    $buildCode
);
if (($buildCode ?? 1) !== 0) {
    fwrite(STDERR, "FAIL  cannot build MukkitXxxProbe.phar\n");
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

/** 玩家会看到的文本：TextPacket.message + log.msg。 */
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

/** 合并一个 tick 内所有目标 op 的负载。 */
$payloadOf = static function (array $ops, string $kind): array {
    $merged = [];
    foreach ($ops as $op) {
        if (($op['o'] ?? '') !== $kind) {
            continue;
        }
        foreach (['s', 'slots', 'b', 'tiles'] as $key) {
            if (isset($op[$key]) && is_array($op[$key])) {
                $merged = array_merge($merged, $op[$key]);
            }
        }
    }
    return $merged;
};

$uuid = '11111111-2222-3333-4444-555555555555';
$profile = new PmProfile($pmRoot, $data, $plugins);
$result = $profile->load([['name' => 'MukkitXxxProbe', 'path' => $phar]]);

$names = array_map(static fn (array $p): string => (string) ($p['name'] ?? ''), $result['loaded']);
$check('xxx probe phar loaded by PM\'s own PluginManager', in_array('MukkitXxxProbe', $names, true), implode(',', $names));
$check('no rejected plugins', $result['rejected'] === [], var_export($result['rejected'], true));

/** 跑一条控制台命令，返回该 tick 的 op。 */
$command = static function (string $line) use ($profile): array {
    return $profile->tick([['k' => 'command', 'sender' => 'console', 'line' => $line]]);
};

/** 问插件它看到什么，返回 report 行。 */
$report = static function () use ($command, $messages): string {
    foreach (explode("\n", $messages($command('xx-report'))) as $line) {
        $at = strpos($line, 'xx-report ');
        if ($at !== false) {
            return trim(substr($line, $at));
        }
    }
    return '';
};

// ---------------------------------------------------------------- 前置：让玩家存在
$profile->tick([[
    'k' => 'event',
    'name' => 'player.join',
    'player' => $uuid,
    'data' => ['name' => 'Steve', 'x' => 0.5, 'y' => 64.0, 'z' => 0.5, 'yaw' => 0.0, 'pitch' => 0.0, 'world' => 'world', 'heldSlot' => 0],
]]);

// ---------------------------------------------------------------- 出站：PM -> op
$ops = $command('xx-do 7');
$payload = $payloadOf($ops, 'player.state'); // 换成被测的 op 类型
$check('the API call reaches the bridge', ($payload['health'] ?? null) === 7.0, (string) json_encode($payload));
$check('it does not drag unrelated fields along', !array_key_exists('x', $payload), (string) json_encode($payload));

// ---------------------------------------------------------------- 不回声
$idle = $profile->tick([]);
$check('the value is reported once, not every tick', $payloadOf($idle, 'player.state') === [], (string) json_encode($idle));

// ---------------------------------------------------------------- 入站：Nukkit -> PM
$profile->tick([['k' => 'player.state', 'player' => $uuid, 's' => ['health' => 3.5]]]);
$check('what Nukkit changes is what the plugin reads back', str_contains($report(), 'value=3.5'), $report());

// ---------------------------------------------------------------- 异常输入
$ops = $command('xx-do -1'); // 换成一个真正越界的值
$payload = $payloadOf($ops, 'player.state');
$check('an out-of-range value is refused rather than applied', $payload === [], (string) json_encode($payload));
$check('and the refusal is reported', str_contains($messages($ops), 'xxx:'), $messages($ops));

// ---------------------------------------------------------------- 汇总
if ($failures > 0) {
    fwrite(STDERR, "xxx FAIL ({$failures})\n");
    exit(1);
}
echo "xxx PASS\n";
