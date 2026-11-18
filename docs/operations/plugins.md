# 真实插件清单（测试服）

桥内那 300+ 条断言用的都是自己写的探针（`examples/pm-*-probe`）。这一页是另一回事：
**Poggit 上的真实插件**，钉版本下载，用来回答"真插件到底能不能跑"。

## 当前装了什么

`bash scripts/fetch-plugins.sh` 下到 `run/server/plugins/`，`scripts/serve.sh` 起服时一起加载。
Docker 默认走 `PLUGINS=real`（`scripts/docker-serve.sh`）：只装下面这 7 个真实插件，
不装任何探针，数据落在 `run/docker-real/`。

| 插件 | 版本 | 覆盖的桥能力 | 进服后的命令 |
|---|---|---|---|
| MultiWorld | 2.1.2 | 世界创建/加载/传送、`WorldManager`、命令与权限 | `/multiworld`（`/mw`） |
| Texter | 4.3.3 | 悬浮文字：实体/名牌、`world` 定位、命令 | `/txt` |
| Wilderness | 2.1.3 | 区域保护：方块事件、玩家位置、命令 | `/wilderness` |
| BedcoreProtect | 1.2.4 | 方块日志/回滚；libasynql SQLite **worker 线程** | `/bedcoreprotect` |
| ChestShop | 5.3.9 | 牌子商店：经济、容器、libasynql **worker 线程** | `/chestshop` |
| ScoreHud | 8.0.0 | 计分板；走 `SetDisplayObjective` / `SetScore` 包映射 | `/scorehud` |
| EconomyAPI | 5.7.2-Dev | 经济 API、命令、数据落在 `plugins/EconomyAPI/` | `/money`、`/setmoney` |

宿主测试服默认是 `MUKKIT_PLUGINS=all`：上面 7 个加上 11 个探针，共 **18 个 PM 插件**。
Docker 的 `PLUGINS=real` 只有 7 个，日志里应当是：

```
Mukkit: 宿主已连接（profile=pm）, 7 个 PM 插件 (BedcoreProtect, ChestShop, EconomyAPI, MultiWorld, ScoreHud, Texter, Wilderness)
```

宿主全量模式（18 个）的日志：

```
Mukkit: 宿主已连接（profile=pm）, 18 个 PM 插件 (... BedcoreProtect, ChestShop, MultiWorld, ScoreHud, Texter, Wilderness)
```

后两个（BedcoreProtect / ChestShop）**只有在带 pmmpthread 的 PHP 下才能启**：它们各自带一份
libasynql，`onEnable` 会起 SQLite worker 线程。stock PHP 下前者让宿主崩、后者让宿主 20 秒内答不出
插件列表；官方 pmmp PHP 下两者都正常。见下面「带线程的 PHP」。

## 试过、装不上的（这是数据，不是抱怨）

| 插件 | 版本 | 卡在哪 |
|---|---|---|
| ShortSneak | 1.1.1 | `plugin.yml` 的 `mcpe-protocol` 只有 `594`，服务端是 `2225` → PM 直接拒绝 |
| PiggyShopUI | 3.0.3 | 只声明 API 4.x（PM4 时代），PM5 不兼容 |
| SimpleHome | 1.1.0 | 同上，API 4.0–4.21 |
| PiggyCustomEnchants | 3.0.9 | 同上，API 4.2–4.21 |
| FlyPE / AutoXP / LockIt | 3.8.3 / 1.1.0 / 1.3.5 | 只支持 API 3.x |

三类原因值得记住：

1. **PM4/PM3 的插件占多数**。Poggit 归档里 2023–2024 的发布物基本是 API 4.x，PM5 只认 5.x。
   挑插件时先看 `info.json` 的 `api` 范围（`from` 的 major 必须是 5）。
2. **自带 libasynql/SQLite 的插件要真线程**。stock PHP 下 fork 的 `pmmp\thread` 是单线程垫片：
   类存在、`start()` 不执行 `run()`，这类插件不是"慢"，是永远不会返回。换成官方 pmmp PHP
   就正常（`scripts/fetch-php.sh`）。
3. **协议号可以单独卡人**。`mcpe-protocol` 只写了一个旧版本号的插件，在 2225 的服务端直接拒。

## 一个坏 phar 能拖垮整个服

PocketMine 的 `Server::__construct()` 在 `loadPlugins()` / `enablePlugins()` 任一步失败时
直接抛出，宿主进程退出，JVM 只看到 `plugin host closed the connection`。也就是说**装一个不兼容
的插件 = 整台服起不来**，不是"那一个插件不生效"。

所以这个清单只放能启的插件；试错的留在上面那张表里。

## 用法

```bash
bash scripts/fetch-plugins.sh                  # 幂等；已存在的跳过
PORT=19133 bash scripts/serve.sh --no-build    # 宿主机，真实插件 + 探针
bash scripts/docker-serve.sh                   # Docker，只装真实插件（推荐）
PLUGINS=probe bash scripts/docker-serve.sh     # Docker，只装探针（L4 冒烟）
```

想加新插件：先看 Poggit 归档的 `info.json`（`api` 的 major 是 5），再单独放进去起一次服确认，
最后才写进 `fetch-plugins.sh`。

## 下一步

真机逐条（`docs/operations/smoke.md`）之外，这三个插件各自能给出一批"桥外"的反馈：

- MultiWorld：`/mw create` + `/mw tp` → 世界创建/卸载与传送是否真的落到 Nukkit
- Texter：`/txt add` → 悬浮文字的名牌与位置是否跟着实体镜像走
- Wilderness：圈一块地再挖 → 方块事件与 PM 侧的拒绝是否一致

## 带线程的 PHP（可选，但推荐）

```bash
bash scripts/fetch-php.sh        # 下载官方 pmmp PHP 8.4 (ZTS + pmmpthread) 到 vendor/php/<platform>
```

`scripts/serve.sh` 检测到 `vendor/php/<platform>` 就会把 `MUKKIT_PHP_BINARY` 指过去（也可以用同名环境变量
手动指定）。此时宿主的 `AsyncPool` 是**真 worker 线程**，`AsyncTask` 与上游 PocketMine 一致：
并行执行、`publishProgress()` 有效、timings 记录。没有它则退回同步池 —— 功能都在，但任何在
`AsyncTask` 里做阻塞 I/O 的插件（更新检查、bStats、数据库）都会吃掉 tick，重则触发宿主硬超时。

这也是 BedcoreProtect / ChestShop 能不能启的分界线。`docs/design/pm-fork.md` 的
「两档 AsyncPool」记了原理与两条约束（ThreadSafe 里不能存闭包、任务里不能捕获非线程安全对象）。
