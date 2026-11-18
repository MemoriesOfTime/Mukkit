#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * 玩家命令入口判据（宿主侧）：Nukkit 上玩家敲的命令，到 PocketMine 这边必须是**玩家**在敲。
 *
 * 适配器把"玩家敲了 Nukkit 不认识的命令"变成一条带 `player` 的 `command` op。这里验的是宿主
 * 收下之后的行为，全部用 `examples/pm-probe` 里那个普通的 `PluginBase` 命令：
 *
 *   1. 带 `player` 的命令以 Player 身份执行（sender=player，名字是那个玩家）
 *   2. 回复作为 packet op 回到**该玩家**，不是控制台
 *   3. 前导斜杠只是标点，不进入命令名
 *   4. 不带 `player` 的命令仍然是控制台（原有路径不回归）
 *   5. 已退出/未知 uuid 的命令被丢弃，绝不降级成控制台执行
 *   6. 谁也不认识的命令留一条 warn，而不是静默成功
 *
 * 与 `pm-player-test.php` 的区别：那条用 `player.chat="/probe"` 走 PM 自己的 `Player::chat()`
 * 分流，证明宿主能按玩家身份执行；这条喂的是适配器**真正会发**的 op 形状，证明新入口的契约。
 *
 * Usage: php pm-host/tests/pm-command-test.php
 */

require __DIR__ . '/../src/bootstrap.php';

use Mukkit\Host\PmProfile;

$root = dirname(__DIR__, 2);
$pmRoot = getenv('MUKKIT_PM_ROOT') ?: dirname($root) . '/PocketMine-MP';

if (!is_file($pmRoot . '/src/mukkit/polyfill.php') || !is_file($pmRoot . '/vendor/autoload.php')) {
    fwrite(STDERR, "FAIL  fork not usable at {$pmRoot} (need src/mukkit/polyfill.php + vendor/autoload.php)\n");
    exit(1);
}

$base = $root . '/run/pm-command';
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

/** Packet ops addressed to one player, as their message text. */
$messagesTo = static function (array $ops, string $target): string {
    $out = '';
    foreach ($ops as $op) {
        if (($op['o'] ?? '') === 'packet' && ($op['to'] ?? '') === $target) {
            $out .= (string) ($op['f']['message'] ?? '');
        }
    }
    return $out;
};

$uuid = '11111111-2222-3333-4444-555555555555';
$ghost = '99999999-8888-7777-6666-555555555555';

$profile = new PmProfile($pmRoot, $data, $plugins);
$result = $profile->load([['name' => 'MukkitProbe', 'path' => $phar]]);
$names = array_map(static fn (array $p): string => (string) ($p['name'] ?? ''), $result['loaded']);
$check('probe phar loaded by PM\'s own PluginManager', in_array('MukkitProbe', $names, true), implode(',', $names));

$profile->tick([[
    'k' => 'event',
    'name' => 'player.join',
    'player' => $uuid,
    'data' => ['name' => 'Steve', 'x' => 12.5, 'y' => 64.0, 'z' => -3.25, 'yaw' => 90.0, 'pitch' => 0.0, 'world' => 'world'],
]]);

// ---------------------------------------------------------------- as the player
$ops = $profile->tick([[
    'k' => 'command',
    'sender' => 'Steve',
    'player' => $uuid,
    'line' => 'cmdprobe alpha beta',
]]);
$output = $text($ops);

$check(
    'a command sent with a player uuid runs as that player',
    str_contains($output, 'probe cmd sender=player name=Steve args=alpha beta'),
    trim($output)
);
$check(
    'the reply went back to that player, not to console',
    str_contains($messagesTo($ops, $uuid), 'probe cmd sender=player name=Steve args=alpha beta'),
    $messagesTo($ops, $uuid) === '' ? 'no packet op' : $messagesTo($ops, $uuid)
);
$check(
    'nothing was answered on the console stream',
    !str_contains($output, 'sender=console'),
    trim($output)
);

// A leading slash is punctuation. The adapter strips it, but the host must not care either way -
// an older or hand-written op in that shape should still run.
$ops = $profile->tick([[
    'k' => 'command',
    'sender' => 'Steve',
    'player' => $uuid,
    'line' => '/cmdprobe one two',
]]);
$check(
    'a leading slash is punctuation, not part of the name',
    str_contains($text($ops), 'args=one two'),
    trim($text($ops))
);

// ---------------------------------------------------------------- console still works
$ops = $profile->tick([['k' => 'command', 'sender' => 'console', 'line' => 'cmdprobe']]);
$check(
    'a command with no player uuid is still console',
    str_contains($text($ops), 'probe cmd sender=console name=CONSOLE args='),
    trim($text($ops))
);

// ---------------------------------------------------------------- gone players
$ops = $profile->tick([[
    'k' => 'command',
    'sender' => 'Nobody',
    'player' => $ghost,
    'line' => 'cmdprobe ghost',
]]);
$output = $text($ops);
$check(
    'a command from a uuid that was never proxied is dropped',
    str_contains($output, 'command from unknown player') && str_contains($output, $ghost),
    trim($output)
);
$check(
    'and is not quietly run as console',
    !str_contains($output, 'sender=console') && !str_contains($messagesTo($ops, $ghost), 'probe cmd'),
    trim($output)
);

// ---------------------------------------------------------------- unknown command
$ops = $profile->tick([[
    'k' => 'command',
    'sender' => 'Steve',
    'player' => $uuid,
    'line' => 'nosuchthing here',
]]);
$check(
    'a command no plugin owns is reported, not silently accepted',
    str_contains($text($ops), 'command not handled by any plugin: nosuchthing here'),
    trim($text($ops))
);

// ---------------------------------------------------------------- malformed
$ops = $profile->tick([['k' => 'command', 'sender' => 'Steve', 'player' => $uuid, 'line' => '   ']]);
$check(
    'an empty line is an error for that tick, not a crash',
    str_contains($text($ops), 'pm command failed: command requires a non-empty line'),
    trim($text($ops))
);

// ---------------------------------------------------------------- after quit
$profile->tick([[
    'k' => 'event',
    'name' => 'player.quit',
    'player' => $uuid,
    'data' => ['name' => 'Steve', 'reason' => 'client quit'],
]]);
$ops = $profile->tick([[
    'k' => 'command',
    'sender' => 'Steve',
    'player' => $uuid,
    'line' => 'cmdprobe afterquit',
]]);
$check(
    'a command that arrives after the quit is dropped, not run for a ghost',
    str_contains($text($ops), 'command from unknown player') && !str_contains($text($ops), 'probe cmd'),
    trim($text($ops))
);

$profile->shutdown();

if ($failures > 0) {
    fwrite(STDERR, "\npm player command: {$failures} failure(s)\n");
    exit(1);
}

echo "\npm player command PASS\n";
exit(0);
