# 宿主侧接口（PM 产出 op、读入结果）

## 构造与驱动

```php
require __DIR__ . '/../src/bootstrap.php';

use Mukkit\Host\PmProfile;

$profile = new PmProfile($pmRoot, $dataDir, $pluginsDir); // 真 fork 根目录、插件数据、插件目录
$result  = $profile->load([['name' => 'MukkitXxxProbe', 'path' => $phar]]);
// $result['loaded']   = [['name' => ..., 'path' => ...], ...]
// $result['rejected'] = []   ← 必须断言为空

$ops = $profile->tick($inbound); // 一个 tick，返回该 tick 产出的 op 列表
```

`$pmRoot` 默认 `dirname($root) . '/PocketMine-MP'`，可用 `MUKKIT_PM_ROOT` 覆盖。可用性判据是两个文件都在：

```php
is_file($pmRoot . '/src/mukkit/polyfill.php') && is_file($pmRoot . '/vendor/autoload.php')
```

## 入站帧（`$inbound` 的元素）

| `k` | 其它字段 | 用途 |
|---|---|---|
| `command` | `sender`（`console` 或玩家 UUID）、`line` | 触发插件命令，最常用 |
| `event` | `name`、`player`、`data`（坐标/世界/heldSlot 等） | 模拟 join/quit/chat/death 等 |
| `player.state` | `player`、`s`（状态字段表） | 模拟 Nukkit 侧改状态，验证插件读得到 |
| `player.inventory` | `player`、`slots` | 背包反向 |
| `block.update` | `world`、`b` | 方块反向 |
| `chunk` / `chunk.unload` | 区块数据 | 世界镜像 |
| `tile` | `world`、`tiles` | 方块实体 |
| `world.state` | `world`、`s` | 时间/天气 |
| `entity.sync` | `world`、`e` | 实体 |
| `form.response` | 表单 id、响应 | 表单回包 |

`command` 的 `sender=console` 最省事；需要玩家视角时传玩家 UUID，但前提是该玩家已经 join 过。

## 出站 op 形态（`o` 字段判别）

| `o` | 关键字段 | 说明 |
|---|---|---|
| `packet` | `to`、`cls`、`f` | `f` 是包字段表，如 `TextPacket` 的 `message` |
| `log` | `level`、`msg` | 插件日志；控制台输出也走这里 |
| `player.state` | `to`、`s` | `s` 只带变化的字段，坐标组会一起带 |
| `player.inventory` | `to`、`slots` | |
| `block` | `world`、`b`（`x,y,z,id,meta`） | |
| `tile` | `world`、`tiles` | |
| `world.state` | `world`、`s` | |
| `entity.state` | `world`、`e` | |
| `window.open` / `content` / `slot` / `close` / `data` | `to`、`window`、… | 容器 |
| `event.result` | `id`、`allowed` | 决定型事件回执 |
| `capabilities` | `events` | 宿主能力声明 |

字段级定义以 `pm-host/src/Protocol.php` 和 `docs/design/protocol.md` 为准；两处不一致时先修文档再写判据。

## 断言 helper（照抄即可）

```php
$failures = 0;
$check = static function (string $label, bool $ok, string $detail = '') use (&$failures): void {
    if ($ok) {
        echo "ok    {$label}\n";
        return;
    }
    echo "FAIL  {$label}" . ($detail === '' ? '' : " ({$detail})") . "\n";
    $failures++;
};

/** 玩家会看到的文本：TextPacket.message + log.msg */
$messages = static function (array $ops): string { /* ... */ };

/** 合并一个 tick 内所有 player.state 的 s */
$stateOf = static function (array $ops): array { /* ... */ };

/** 跑一条命令并抓插件的 report 行（按 payload 匹配，不按行首） */
$report = static function () use ($command): string {
    foreach (explode("\n", $messages($command('xx-report'))) as $line) {
        $at = strpos($line, 'xx-report ');
        if ($at !== false) {
            return trim(substr($line, $at));
        }
    }
    return '';
};
```

结束：

```php
if ($failures > 0) {
    fwrite(STDERR, "xxx FAIL ({$failures})\n");
    exit(1);
}
echo "xxx PASS\n";
```

## 每次跑干净的目录

```php
$base = $root . '/run/pm-xxx';
$plugins = $base . '/plugins';
$data = $base . '/data';
// 递归删 $base（仓库里的既有写法是内联 $rmrf 闭包，不调外部 rm）
```

必须删的原因：PM 的 `PluginManager` 会缓存已加载的 phar；插件自己的 `plugin_data` 也会留下上一轮的 config/状态，导致「onEnable 跑过」这类断言变成历史证据。

## 打包

```bash
bash scripts/build-plugin-phar.sh examples/pm-xxx-probe run/pm-xxx/plugins/MukkitXxxProbe.phar
```

判据里用 `passthru` 调它，检查退出码；不要 `require` 源码目录冒充插件。
